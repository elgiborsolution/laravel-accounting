<?php

namespace ESolution\LaravelAccounting\Tests\Feature;

use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use ESolution\LaravelAccounting\Models\FiscalPeriod;
use ESolution\LaravelAccounting\Models\MonthlyBalance;
use ESolution\LaravelAccounting\Repositories\AccountCategoryRepository;
use ESolution\LaravelAccounting\Repositories\AccountRepository;
use ESolution\LaravelAccounting\Services\ClosingService;
use ESolution\LaravelAccounting\Services\ConsolidatedReportService;
use ESolution\LaravelAccounting\Services\ReportService;
use ESolution\LaravelAccounting\Support\TenantReportRunner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Chart (same codes in every master database): ASSET > CURRENT_ASSET > CASH (1001, 9999 owned by
 * tenant-c), CURRENT_ASSET holds 1101 (owned by tenant-a) and 1102 (owned by tenant-b), and ASSET >
 * ASSET_CLEARING is LIABILITY-typed (credit-normal, account 1201). Roots LIABILITY (2001),
 * EQUITY (3001), REVENUE (4001), EXPENSE (5001).
 *
 * tenant-a: May 1001 D100/3001 K100, 1101 D40/4001 K40 (May closed by ClosingService); June open:
 * 5001 D15/1001 K15, 1001 D30/1201 K30, 1102 D7/2001 K7 (1102 is invisible to tenant-a).
 * tenant-b: May 1001 D50/2001 K50, 1102 D20/4001 K20; June 5001 D5/1102 K5; May and June closed.
 */
class CategoryBalanceTreeTest extends TestCase
{
    private object $tenancy;

    private bool $shared = false;

    private array $accountIds = [];

    public static function masterModes(): array
    {
        return ['tenant-local master' => [false], 'shared master' => [true]];
    }

    #[DataProvider('masterModes')]
    public function test_consolidated_tree_has_opening_movements_and_ending_on_every_node(bool $shared): void
    {
        $this->prepareDatabases($shared);
        DB::setDefaultConnection('cbt_original');

        $result = app(ConsolidatedReportService::class)->categoryBalanceTree(2026, 6, ['tenant-a', 'alpha', 'beta']);

        $this->assertSame(['tenant-a', 'tenant-b'], $result['tenant_ids']);
        $this->assertSame(['year' => 2026, 'month' => 6], $result['period']);
        // tenant-a June has no snapshots (journal calculation); tenant-b June is closed.
        $this->assertSame('open', $result['status']);
        $this->assertSame([
            ['tenant_id' => 'tenant-a', 'status' => 'open', 'reason' => null],
            ['tenant_id' => 'tenant-b', 'status' => 'current', 'reason' => null],
        ], $result['snapshots']);
        $this->assertSame(['ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'EXPENSE'], array_column($result['data'], 'category_code'));

        $nodes = $this->index($result['data']);
        $this->assertSame([
            'ASSET' => ['debit', 210.0, 30.0, 50.0, 190.0],
            'CURRENT_ASSET' => ['debit', 210.0, 30.0, 20.0, 220.0],
            'CASH' => ['debit', 150.0, 30.0, 15.0, 165.0],
            'ASSET_CLEARING' => ['credit', 0.0, 0.0, 30.0, 30.0],
            'LIABILITY' => ['credit', 50.0, 0.0, 7.0, 57.0],
            'EQUITY' => ['credit', 100.0, 0.0, 0.0, 100.0],
            'REVENUE' => ['credit', 60.0, 0.0, 0.0, 60.0],
            'EXPENSE' => ['debit', 0.0, 20.0, 0.0, 20.0],
        ], array_map(fn (array $node) => $this->amounts($node), $nodes['categories']));
        $this->assertSame([
            '1001' => ['debit', 150.0, 30.0, 15.0, 165.0],
            '1101' => ['debit', 40.0, 0.0, 0.0, 40.0],
            // Owned by tenant-b: tenant-a's posting to it is not visible to tenant-a's source.
            '1102' => ['debit', 20.0, 0.0, 5.0, 15.0],
            '1201' => ['credit', 0.0, 0.0, 30.0, 30.0],
            '2001' => ['credit', 50.0, 0.0, 7.0, 57.0],
            '3001' => ['credit', 100.0, 0.0, 0.0, 100.0],
            '4001' => ['credit', 60.0, 0.0, 0.0, 60.0],
            '5001' => ['debit', 0.0, 20.0, 0.0, 20.0],
        ], array_map(fn (array $account) => $this->amounts($account), $nodes['accounts']));
        $this->assertArrayNotHasKey('9999', $nodes['accounts'], 'accounts of unselected tenants stay hidden');

        $cash = $result['data'][0]['children'][0]['children'][0];
        $this->assertSame([
            'category_code' => 'CASH', 'parent_code' => 'CURRENT_ASSET', 'type' => 'ASSET', 'category_name' => 'Cash',
            'report_type' => 'BS', 'status' => true, 'sequence_no' => 3, 'normal_balance' => 'debit',
            'opening_balance' => 150.0, 'total_debit' => 30.0, 'total_credit' => 15.0, 'ending_balance' => 165.0,
            'accounts' => [[
                'code' => '1001', 'category_code' => 'CASH', 'name' => 'Account 1001', 'is_postable' => true, 'status' => true,
                'normal_balance' => 'debit', 'opening_balance' => 150.0, 'total_debit' => 30.0, 'total_credit' => 15.0, 'ending_balance' => 165.0,
            ]],
            'children' => [],
        ], $cash);
        $this->assertNodeArithmetic($result['data']);

        $this->assertSame(['tenant-a', 'tenant-b'], $this->tenancy->initializations);
        $this->assertFalse($this->tenancy->initialized);
        $this->assertSame('cbt_original', DB::getDefaultConnection());
    }

    #[DataProvider('masterModes')]
    public function test_closed_months_use_snapshots_and_months_before_activity_are_open_zeroes(bool $shared): void
    {
        $this->prepareDatabases($shared);
        $service = app(ConsolidatedReportService::class);

        $may = $service->categoryBalanceTree(2026, 5, ['tenant-a', 'tenant-b']);
        $this->assertSame('current', $may['status']);
        $this->assertSame(['current', 'current'], array_column($may['snapshots'], 'status'));
        $nodes = $this->index($may['data']);
        $this->assertSame(['debit', 0.0, 150.0, 0.0, 150.0], $this->amounts($nodes['accounts']['1001']));
        $this->assertSame(['debit', 0.0, 210.0, 0.0, 210.0], $this->amounts($nodes['categories']['ASSET']));
        $this->assertNodeArithmetic($may['data']);

        // A closed month's stored snapshot is the source of its amounts.
        MonthlyBalance::on('cbt_b')->where('fiscal_month', 5)->where('account_id', $this->accountId('1001'))
            ->update(['total_debit' => 60, 'ending_balance' => 60]);
        $this->assertSame(['debit', 0.0, 160.0, 0.0, 160.0],
            $this->amounts($this->index($service->categoryBalanceTree(2026, 5, ['tenant-a', 'tenant-b'])['data'])['accounts']['1001']));

        $april = $service->categoryBalanceTree(2026, 4, ['tenant-a', 'tenant-b']);
        $this->assertSame('open', $april['status']);
        $this->assertSame([[0.0, 0.0, 0.0, 0.0]], array_values(array_unique(array_map(
            fn (array $node) => array_slice($this->amounts($node), 1), $this->index($april['data'])['categories']
        ), SORT_REGULAR)));

        // A single tenant is not consolidated with anyone else.
        $single = $this->index($service->categoryBalanceTree(2026, 6, ['tenant-b'])['data']);
        $this->assertSame(['debit', 20.0, 0.0, 5.0, 15.0], $this->amounts($single['accounts']['1102']));
        $this->assertSame(['credit', 50.0, 0.0, 0.0, 50.0], $this->amounts($single['accounts']['2001']));
        $this->assertArrayNotHasKey('1101', $single['accounts'], 'tenant-a owned accounts are not visible to tenant-b');
    }

    public static function staleScenarios(): array
    {
        return [
            'reopened month keeps its snapshots' => ['reopened', 6, 'reopened'],
            'open month whose carry-forward base was reopened' => ['reopened', 7, 'reopened'],
            'earlier month closed after the requested month' => ['out_of_order', 6, 'broken_chain'],
            'earlier month with journals never closed' => ['gap', 6, 'broken_chain'],
            'legacy credit-normal asset snapshot in the chain' => ['legacy_sign', 6, 'legacy_sign'],
            'legacy snapshot in the requested month itself' => ['legacy_sign', 5, 'legacy_sign'],
        ];
    }

    #[DataProvider('staleScenarios')]
    public function test_stale_snapshots_withhold_every_amount_and_report_the_reason(string $scenario, int $month, string $reason): void
    {
        $this->prepareDatabases(false);
        match ($scenario) {
            'reopened' => app(TenantReportRunner::class)->run(['tenant-b'], fn () => app(ClosingService::class)->reopenMonth(2026, 6)),
            'out_of_order' => FiscalPeriod::on('cbt_b')->where('month', 5)->update(['closed_at' => '2026-09-01 00:00:00']),
            'gap' => $this->journal('cbt_b', '2026-04-15', ['1001' => 1, '3001' => -1]),
            // closeMonth() before its case-insensitive type fix stored ending = opening + credit - debit.
            'legacy_sign' => MonthlyBalance::on('cbt_b')->where('fiscal_month', 5)->where('account_id', $this->accountId('1001'))
                ->update(['ending_balance' => -50]),
        };
        $service = app(ConsolidatedReportService::class);

        $result = $service->categoryBalanceTree(2026, $month, ['tenant-a', 'tenant-b']);
        $this->assertSame('stale', $result['status']);
        $this->assertSame(['tenant_id' => 'tenant-b', 'status' => 'stale', 'reason' => $reason], $result['snapshots'][1]);
        $this->assertSame('tenant-a', $result['snapshots'][0]['tenant_id']);
        $this->assertNotSame('stale', $result['snapshots'][0]['status']);
        // The chart is still returned, but no amount is presented as a current figure.
        $nodes = $this->index($result['data']);
        $this->assertCount(8, $nodes['categories']);
        foreach ([...$nodes['categories'], ...$nodes['accounts']] as $node) {
            $this->assertSame([null, null, null, null], array_slice($this->amounts($node), 1));
        }

        // Unaffected tenants still report on their own.
        $alone = $service->categoryBalanceTree(2026, $month, ['tenant-a']);
        $this->assertNotSame('stale', $alone['status']);
        $this->assertNotNull($alone['data'][0]['ending_balance']);
    }

    public function test_reclosing_a_reopened_month_makes_it_current_with_the_new_postings(): void
    {
        $this->prepareDatabases(false);
        $runner = app(TenantReportRunner::class);
        $runner->run(['tenant-b'], fn () => app(ClosingService::class)->reopenMonth(2026, 6));
        $this->journal('cbt_b', '2026-06-25', ['1001' => 9, '4001' => -9]);
        $this->assertSame('stale', app(ConsolidatedReportService::class)->categoryBalanceTree(2026, 6, ['tenant-b'])['status']);

        $runner->run(['tenant-b'], fn () => app(ClosingService::class)->closeMonth(2026, 6));
        $result = app(ConsolidatedReportService::class)->categoryBalanceTree(2026, 6, ['tenant-b']);
        $this->assertSame([['tenant_id' => 'tenant-b', 'status' => 'current', 'reason' => null]], $result['snapshots']);
        $this->assertSame(['debit', 50.0, 9.0, 0.0, 59.0], $this->amounts($this->index($result['data'])['accounts']['1001']));
        $this->assertNodeArithmetic($result['data']);
    }

    public static function invalidInputs(): array
    {
        return [
            'month 13' => [2026, 13, ['tenant-a'], 'month'],
            'month 0' => [2026, 0, ['tenant-a'], 'month'],
            'year 0' => [0, 6, ['tenant-a'], 'year'],
            'empty tenant list' => [2026, 6, [], 'tenant_ids'],
            'unknown tenant after a valid one' => [2026, 6, ['tenant-a', 'missing'], 'tenant_ids.1'],
            'nested tenant id' => [2026, 6, [['tenant-a']], 'tenant_ids.0'],
            'non-list tenant ids' => [2026, 6, ['first' => 'tenant-a'], 'tenant_ids'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_period_and_tenants_are_validated_before_any_tenant_runs(int $year, int $month, array $ids, string $field): void
    {
        $this->prepareDatabases(false);
        try {
            app(ConsolidatedReportService::class)->categoryBalanceTree($year, $month, $ids);
            $this->fail('Invalid input must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
        $this->assertSame([], $this->tenancy->initializations);
        $this->assertSame('testbench', DB::getDefaultConnection());
    }

    public function test_unsupported_category_types_are_rejected_after_context_restoration(): void
    {
        $this->prepareDatabases(false);
        // A host may have altered the enum column; SQLite enforces it as a check constraint.
        foreach (['cbt_a', 'cbt_b'] as $connection) {
            DB::connection($connection)->statement('PRAGMA ignore_check_constraints = ON');
            DB::connection($connection)->table('acc_account_categories')->where('category_code', 'ASSET_CLEARING')->update(['type' => 'contra']);
        }

        try {
            app(ConsolidatedReportService::class)->categoryBalanceTree(2026, 6, ['tenant-a', 'tenant-b']);
            $this->fail('An unsupported category type must be rejected.');
        } catch (ValidationException $e) {
            $this->assertSame(['Category [ASSET_CLEARING] has unsupported type [CONTRA] for a normal balance.'], $e->errors()['consolidation']);
        }
        $this->assertFalse($this->tenancy->initialized);
        $this->assertSame('testbench', DB::getDefaultConnection());
    }

    public function test_incompatible_definitions_across_tenants_are_rejected(): void
    {
        $this->prepareDatabases(false);
        AccountCategory::on('cbt_b')->where('category_code', 'ASSET_CLEARING')->update(['type' => 'asset']);

        try {
            app(ConsolidatedReportService::class)->categoryBalanceTree(2026, 6, ['tenant-a', 'tenant-b']);
            $this->fail('Incompatible definitions must be rejected.');
        } catch (ValidationException $e) {
            $this->assertSame(['Category code [ASSET_CLEARING] has incompatible [type] in tenant [tenant-b].'], $e->errors()['consolidation']);
        }
        $this->assertFalse($this->tenancy->initialized);
    }

    public function test_existing_category_tree_output_is_unchanged(): void
    {
        $this->prepareDatabases(false);
        $tree = app(ConsolidatedReportService::class)->categoryTree(['tenant-a', 'tenant-b'], 2026, 6, true);
        $asset = $tree['data'][0];

        $this->assertSame(['category_code', 'parent_code', 'type', 'category_name', 'report_type', 'status', 'children', 'accounts', 'balance', 'sequence_no'], array_keys($asset));
        // categoryTree() keeps summing ending balances without sign conversion (220 + 30).
        $this->assertSame(250.0, $asset['balance']);
        $this->assertSame(['code', 'category_code', 'name', 'is_postable', 'status', 'balance', 'description'],
            array_keys($asset['children'][0]['accounts'][0]));
    }

    /** Every category: debit-normal ending = opening + debit - credit, credit-normal ending = opening + credit - debit. */
    private function assertNodeArithmetic(array $nodes): void
    {
        foreach ($this->index($nodes)['categories'] + $this->index($nodes)['accounts'] as $code => $node) {
            $movement = $node['normal_balance'] === 'debit'
                ? $node['total_debit'] - $node['total_credit']
                : $node['total_credit'] - $node['total_debit'];
            $this->assertEqualsWithDelta($node['opening_balance'] + $movement, $node['ending_balance'], 0.001, (string) $code);
        }
    }

    private function index(array $nodes): array
    {
        $index = ['categories' => [], 'accounts' => []];
        $walk = function (array $nodes) use (&$walk, &$index): void {
            foreach ($nodes as $node) {
                $index['categories'][$node['category_code']] = $node;
                foreach ($node['accounts'] as $account) {
                    $index['accounts'][$account['code']] = $account;
                }
                $walk($node['children']);
            }
        };
        $walk($nodes);
        ksort($index['accounts'], SORT_STRING);

        return $index;
    }

    private function amounts(array $node): array
    {
        return [$node['normal_balance'], $node['opening_balance'], $node['total_debit'], $node['total_credit'], $node['ending_balance']];
    }

    private function accountId(string $code): string
    {
        return $this->accountIds[$this->shared ? 'cbt_master' : 'cbt_b'][$code];
    }

    /** Posted journal; $lines maps account code => signed amount (positive debit, negative credit). */
    private function journal(string $connection, string $date, array $lines): void
    {
        $id = (string) Str::uuid();
        DB::connection($connection)->table('acc_journal_entries')->insert([
            'id' => $id, 'journal_no' => 'JV-'.Str::random(8), 'trx_date' => $date, 'status' => 'posted',
        ]);
        foreach ($lines as $code => $amount) {
            DB::connection($connection)->table('acc_journal_entry_details')->insert([
                'id' => (string) Str::uuid(), 'journal_entry_id' => $id,
                'account_id' => $this->accountIds[$this->shared ? 'cbt_master' : $connection][(string) $code],
                'debit' => max($amount, 0), 'credit' => max(-$amount, 0),
            ]);
        }
    }

    private function prepareDatabases(bool $shared): void
    {
        $this->shared = $shared;
        $this->travelTo(Carbon::parse('2026-08-20 12:00:00'));
        foreach (['cbt_registry', 'cbt_master', 'cbt_a', 'cbt_b', 'cbt_original'] as $connection) {
            config()->set('database.connections.'.$connection, [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
            ]);
            DB::purge($connection);
        }
        Schema::connection('cbt_registry')->create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('slug')->unique();
            $table->string('connection_name');
        });
        foreach (['tenant-a' => ['alpha', 'cbt_a'], 'tenant-b' => ['beta', 'cbt_b'], 'original' => ['original', 'cbt_original']] as $id => [$slug, $connection]) {
            CategoryBalanceTenant::create(['id' => $id, 'slug' => $slug, 'connection_name' => $connection]);
        }
        config()->set('tenancy.tenant_model', CategoryBalanceTenant::class);
        config()->set('accounting.master_data.use_shared_database', $shared);
        config()->set('accounting.master_data.connection', 'cbt_master');

        foreach ($shared ? ['cbt_master'] : ['cbt_a', 'cbt_b'] as $connection) {
            $this->createMasterData($connection);
        }
        foreach (['cbt_a', 'cbt_b'] as $connection) {
            DB::setDefaultConnection($connection);
            foreach (['2026_01_01_000005_create_acc_journal_entries_table.php', '2026_01_01_000006_create_acc_journal_entry_details_table.php',
                '2026_01_01_000007_create_acc_fiscal_periods_table.php', '2026_01_01_000008_create_acc_monthly_balances_table.php'] as $migration) {
                (require $this->migration($migration))->up();
            }
        }
        DB::setDefaultConnection('testbench');
        $this->tenancy = new class
        {
            public bool $initialized = false;

            public ?Model $tenant = null;

            public array $initializations = [];

            public function initialize(Model $tenant): void
            {
                $this->initializations[] = $tenant->getKey();
                $this->tenant = $tenant;
                $this->initialized = true;
                DB::setDefaultConnection($tenant->connection_name);
            }

            public function end(): void
            {
                $this->tenant = null;
                $this->initialized = false;
                DB::setDefaultConnection('testbench');
            }
        };
        $this->app->instance(TenantReportRunner::class, new class($this->tenancy) extends TenantReportRunner
        {
            public function __construct(private object $hostTenancy) {}

            protected function tenancy(): object
            {
                return $this->hostTenancy;
            }
        });
        $this->app->singleton(ConsolidatedReportService::class, fn ($app) => new CategoryBalanceConsolidationService(
            $app->make(ReportService::class), $app->make(TenantReportRunner::class),
            $app->make(AccountCategoryRepository::class), $app->make(AccountRepository::class), $this->tenancy
        ));

        $this->journal('cbt_a', '2026-05-10', ['1001' => 100, '3001' => -100]);
        $this->journal('cbt_a', '2026-05-20', ['1101' => 40, '4001' => -40]);
        $this->journal('cbt_b', '2026-05-15', ['1001' => 50, '2001' => -50]);
        $this->journal('cbt_b', '2026-05-25', ['1102' => 20, '4001' => -20]);
        $runner = app(TenantReportRunner::class);
        $runner->run(['tenant-a', 'tenant-b'], fn () => app(ClosingService::class)->closeMonth(2026, 5));
        $this->journal('cbt_a', '2026-06-05', ['5001' => 15, '1001' => -15]);
        $this->journal('cbt_a', '2026-06-12', ['1001' => 30, '1201' => -30]);
        $this->journal('cbt_a', '2026-06-20', ['1102' => 7, '2001' => -7]);
        $this->journal('cbt_b', '2026-06-08', ['5001' => 5, '1102' => -5]);
        $runner->run(['tenant-b'], fn () => app(ClosingService::class)->closeMonth(2026, 6));
        $this->tenancy->initializations = [];
    }

    private function createMasterData(string $connection): void
    {
        DB::setDefaultConnection($connection);
        foreach (['2026_01_01_000001_create_acc_account_categories_table.php', '2026_01_01_000002_create_acc_accounts_table.php',
            '2026_01_01_000003_create_acc_services_table.php', '2026_06_30_000011_add_parent_id_to_acc_account_categories_table.php'] as $migration) {
            (require $this->migration($migration))->up();
        }
        $categories = [];
        $sequence = 0;
        foreach ([
            'ASSET' => [null, 'asset', 'Assets'], 'CURRENT_ASSET' => ['ASSET', 'asset', 'Current assets'], 'CASH' => ['CURRENT_ASSET', 'asset', 'Cash'],
            'ASSET_CLEARING' => ['ASSET', 'liability', 'Asset clearing'], 'LIABILITY' => [null, 'liability', 'Liabilities'],
            'EQUITY' => [null, 'equity', 'Equity'], 'REVENUE' => [null, 'revenue', 'Revenue'], 'EXPENSE' => [null, 'expense', 'Expenses'],
        ] as $code => [$parent, $type, $name]) {
            $categories[$code] = AccountCategory::on($connection)->create([
                'parent_id' => $parent === null ? null : $categories[$parent], 'type' => $type, 'sequence_no' => ++$sequence,
                'category_code' => $code, 'category_name' => $name, 'status' => true,
                'report_type' => in_array($type, ['revenue', 'expense'], true) ? 'PL' : 'BS',
            ])->id;
        }
        foreach (['1001' => ['CASH', null], '9999' => ['CASH', 'tenant-c'], '1101' => ['CURRENT_ASSET', 'tenant-a'],
            '1102' => ['CURRENT_ASSET', 'tenant-b'], '1201' => ['ASSET_CLEARING', null], '2001' => ['LIABILITY', null],
            '3001' => ['EQUITY', null], '4001' => ['REVENUE', null], '5001' => ['EXPENSE', null]] as $code => [$category, $owner]) {
            $this->accountIds[$connection][(string) $code] = Account::on($connection)->create([
                'category_id' => $categories[$category], 'code' => (string) $code, 'name' => 'Account '.$code,
                'tenant_id' => $owner, 'is_postable' => true, 'status' => true,
            ])->id;
        }
    }

    private function migration(string $filename): string
    {
        return dirname(__DIR__, 2).'/database/migrations/accounting/'.$filename;
    }
}

class CategoryBalanceConsolidationService extends ConsolidatedReportService
{
    public function __construct(ReportService $reports, TenantReportRunner $tenants, AccountCategoryRepository $categories, AccountRepository $accounts, private object $hostTenancy)
    {
        parent::__construct($reports, $tenants, $categories, $accounts);
    }

    protected function categoryTenantIdentifier(): string
    {
        return (string) $this->hostTenancy->tenant->getKey();
    }
}

class CategoryBalanceTenant extends Model
{
    protected $connection = 'cbt_registry';

    protected $table = 'tenants';

    protected $guarded = [];

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
