<?php

namespace ESolution\LaravelAccounting\Tests\Feature;

use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use ESolution\LaravelAccounting\Models\MonthlyBalance;
use ESolution\LaravelAccounting\Repositories\AccountCategoryRepository;
use ESolution\LaravelAccounting\Repositories\AccountRepository;
use ESolution\LaravelAccounting\Services\ConsolidatedReportService;
use ESolution\LaravelAccounting\Services\ReportService;
use ESolution\LaravelAccounting\Support\TenantReportRunner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ConsolidatedReportServiceTest extends TestCase
{
    private object $tenancy;

    private array $categoryIds = [];

    private array $accountIds = [];

    public static function masterModes(): array
    {
        return ['tenant-local master' => [false], 'shared master' => [true]];
    }

    #[DataProvider('masterModes')]
    public function test_reports_merge_by_code_include_unique_accounts_and_recalculate_subtotals(bool $shared): void
    {
        $this->prepareDatabases($shared);
        DB::setDefaultConnection('consolidation_original');
        $service = app(ConsolidatedReportService::class);

        $profit = $service->profitLoss(2026, 7, ['tenant-a', 'tenant-b']);
        $sheet = $service->balanceSheet(2026, 7, ['tenant-a', 'tenant-b']);

        $this->assertSame(['tenant-a', 'tenant-b'], $profit['tenant_ids']);
        $this->assertSame(['year' => 2026, 'month' => 7], $profit['period']);
        $this->assertSame(415.0, $profit['report']['net_income']);
        $this->assertSame(485.0, $this->category($profit, 'REVENUE')['balance']);
        $this->assertSame(420.0, $this->category($profit, 'SALES')['balance']);
        $this->assertSame(50.0, $this->category($profit, 'SERVICES')['balance']);
        $this->assertSame(70.0, $this->category($profit, 'EXPENSE')['balance']);
        $this->assertSame(400.0, $this->account($profit, '4001')['balance']);
        $this->assertSame(15.0, $this->account($profit, '4000')['balance']);
        $this->assertSame(20.0, $this->account($profit, '4002')['balance']);
        $this->assertSame(50.0, $this->account($profit, '4101')['balance']);
        $this->assertSame(725.0, $sheet['report']['total_assets']);
        $this->assertSame(205.0, $sheet['report']['total_liabilities']);
        $this->assertSame(520.0, $sheet['report']['total_equity']);
        $this->assertSame(725.0, $this->category($sheet, 'CASH')['balance']);
        $this->assertSame(700.0, $this->account($sheet, '1001')['balance']);
        $this->assertSame(25.0, $this->account($sheet, '1002')['balance']);

        foreach ([$profit, $sheet] as $result) {
            foreach ($this->nodes($result['report']['data']) as $category) {
                $this->assertArrayNotHasKey('id', $category);
                $this->assertArrayNotHasKey('parent_id', $category);
                foreach ($category['accounts'] as $account) {
                    $this->assertArrayNotHasKey('id', $account);
                    $this->assertArrayNotHasKey('category_id', $account);
                    $this->assertSame($category['category_code'], $account['category_code']);
                }
            }
        }
        if (! $shared) {
            $this->assertNotSame($this->accountIds['consolidation_a']['4001'], $this->accountIds['consolidation_b']['4001']);
            $this->assertNotSame($this->categoryIds['consolidation_a']['SALES'], $this->categoryIds['consolidation_b']['SALES']);
        }
        $this->assertSame('consolidation_original', DB::getDefaultConnection());
        $this->assertFalse($this->tenancy->initialized);
        $this->assertSame($shared, config('accounting.master_data.use_shared_database'));
        $this->assertSame('consolidation_master', config('accounting.master_data.connection'));
    }

    public function test_duplicate_ids_and_aliases_are_counted_once_and_periods_stay_separate(): void
    {
        $this->prepareDatabases(false);
        $service = app(ConsolidatedReportService::class);

        $july = $service->profitLoss(2026, 7, ['tenant-a', 'alpha', 'tenant-b', 'tenant-a']);
        $august = $service->profitLoss(2026, 8, ['tenant-a', 'tenant-b']);

        $this->assertSame(['tenant-a', 'tenant-b'], $july['tenant_ids']);
        $this->assertSame(415.0, $july['report']['net_income']);
        $this->assertSame(830.0, $august['report']['net_income']);
        $this->assertSame(['year' => 2026, 'month' => 8], $august['period']);
        $this->assertSame(['tenant-a', 'tenant-b', 'tenant-a', 'tenant-b'], $this->tenancy->initializations);
        $this->assertSame(100.0, $service->profitLoss(2026, 7, ['tenant-a'])['report']['net_income']);
    }

    public function test_signed_account_amounts_are_summed_to_two_decimal_places(): void
    {
        $this->prepareDatabases(false);
        foreach (['consolidation_a' => 100.10, 'consolidation_b' => -25.20] as $connection => $amount) {
            MonthlyBalance::on($connection)->where('account_id', $this->accountIds[$connection]['4001'])
                ->where('fiscal_month', 7)->update(['ending_balance' => $amount]);
        }

        $result = app(ConsolidatedReportService::class)->profitLoss(2026, 7, ['tenant-a', 'tenant-b']);

        $this->assertSame(74.9, $this->account($result, '4001')['balance']);
        $this->assertSame(89.9, $result['report']['net_income']);
    }

    public function test_existing_category_and_report_totals_are_not_added_to_account_amounts(): void
    {
        $this->prepareDatabases(false);
        $original = app(ReportService::class);
        $this->app->instance(ReportService::class, new class($original) extends ReportService
        {
            public function __construct(private ReportService $original) {}

            public function profitLoss($year, $month, ?array $tenantIds = null)
            {
                return $this->inflate($this->original->profitLoss($year, $month, $tenantIds));
            }

            public function balanceSheet($year, $month, ?array $tenantIds = null)
            {
                return $this->inflate($this->original->balanceSheet($year, $month, $tenantIds));
            }

            private function inflate(array $report): array
            {
                $inflateNodes = function (array $nodes) use (&$inflateNodes): array {
                    return array_map(function (array $node) use ($inflateNodes) {
                        $node['balance'] = 999999;
                        $node['children'] = $inflateNodes($node['children']);

                        return $node;
                    }, $nodes);
                };
                foreach ($report['data'] as $group => $nodes) {
                    $report['data'][$group] = $inflateNodes($nodes);
                }
                foreach (array_diff(array_keys($report), ['data']) as $total) {
                    $report[$total] = 999999;
                }

                return $report;
            }
        });

        $service = app(ConsolidatedReportService::class);
        $this->assertSame(415.0, $service->profitLoss(2026, 7, ['tenant-a', 'tenant-b'])['report']['net_income']);
        $this->assertSame(725.0, $service->balanceSheet(2026, 7, ['tenant-a', 'tenant-b'])['report']['total_assets']);
    }

    public static function incompatibleDefinitions(): array
    {
        return [
            'category moved outside report' => ['category', 'REVENUE', 'type', 'ASSET'],
            'category parent' => ['category', 'SALES', 'parent_code', 'EXPENSE'],
            'category name' => ['category', 'SALES', 'category_name', 'Different meaning'],
            'category report type' => ['category', 'SALES', 'report_type', 'BS'],
            'category status' => ['category', 'SALES', 'status', false],
            'account moved outside report' => ['account', '4001', 'category_code', 'CASH'],
            'account name' => ['account', '4001', 'name', 'Unrelated receivables'],
            'account postable flag' => ['account', '4001', 'is_postable', false],
            'account status' => ['account', '4001', 'status', false],
        ];
    }

    #[DataProvider('incompatibleDefinitions')]
    public function test_incompatible_codes_are_explicit_errors_and_context_is_restored(string $kind, string $code, string $field, mixed $value): void
    {
        $this->prepareDatabases(false);
        if ($kind === 'category') {
            $record = AccountCategory::on('consolidation_b')->where('category_code', $code)->firstOrFail();
        } else {
            $record = Account::on('consolidation_b')->where('code', $code)->firstOrFail();
        }
        $column = $field;
        if ($field === 'parent_code' || $field === 'category_code') {
            $column = $field === 'parent_code' ? 'parent_id' : 'category_id';
            $value = $this->categoryIds['consolidation_b'][$value];
        }
        $record->update([$column => $value]);
        $original = ConsolidationTenant::findOrFail('original');
        $this->tenancy->initialize($original);
        DB::setDefaultConnection('testbench');

        try {
            app(ConsolidatedReportService::class)->profitLoss(2026, 7, ['tenant-a', 'tenant-b']);
            $this->fail('Incompatible definitions must not be consolidated.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('consolidation', $e->errors());
            $this->assertStringContainsString('['.$code.']', $e->getMessage());
            $this->assertStringContainsString('['.$field.']', $e->getMessage());
            $this->assertStringContainsString('[tenant-b]', $e->getMessage());
        }

        $this->assertSame($original, $this->tenancy->tenant);
        $this->assertSame('testbench', DB::getDefaultConnection());
    }

    public function test_unrelated_definitions_do_not_block_a_profit_loss_report(): void
    {
        $this->prepareDatabases(false);
        Account::on('consolidation_b')->where('code', '1001')->update(['name' => 'Other cash label']);

        $result = app(ConsolidatedReportService::class)->profitLoss(2026, 7, ['tenant-a', 'tenant-b']);

        $this->assertSame(415.0, $result['report']['net_income']);
    }

    public static function invalidLists(): array
    {
        return [
            'empty' => [[]],
            'unknown' => [['missing']],
            'valid then unknown' => [['tenant-a', 'missing']],
            'blank' => [['tenant-a', ' ']],
            'nested' => [[['tenant-a']]],
            'non-list' => [['first' => 'tenant-a']],
        ];
    }

    #[DataProvider('invalidLists')]
    public function test_all_tenants_are_validated_before_report_execution(array $ids): void
    {
        $this->prepareDatabases(false);
        $original = ConsolidationTenant::findOrFail('original');
        $this->tenancy->initialize($original);
        $this->tenancy->initializations = [];

        foreach (['profitLoss', 'balanceSheet'] as $method) {
            try {
                app(ConsolidatedReportService::class)->{$method}(2026, 7, $ids);
                $this->fail('Invalid tenant lists must fail before reports run.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertSame([], $this->tenancy->initializations);
        $this->assertSame($original, $this->tenancy->tenant);
        $this->assertSame('consolidation_original', DB::getDefaultConnection());
    }

    #[DataProvider('masterModes')]
    public function test_query_failures_restore_the_active_tenant_and_connection_override(bool $shared): void
    {
        $this->prepareDatabases($shared);
        Schema::connection('consolidation_b')->drop('acc_monthly_balances');
        $original = ConsolidationTenant::findOrFail('original');
        $this->tenancy->initialize($original);
        DB::setDefaultConnection('testbench');

        foreach (['profitLoss', 'balanceSheet'] as $method) {
            try {
                app(ConsolidatedReportService::class)->{$method}(2026, 7, ['tenant-a', 'tenant-b']);
                $this->fail('A failed tenant must not be skipped.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('acc_monthly_balances', $e->getMessage());
            }
            $this->assertSame($original, $this->tenancy->tenant);
            $this->assertSame('testbench', DB::getDefaultConnection());
        }
    }

    public function test_initialization_failures_restore_inactive_context_without_fallback(): void
    {
        $this->prepareDatabases(false);
        $this->tenancy->failOn = 'tenant-b';
        DB::setDefaultConnection('consolidation_original');

        try {
            app(ConsolidatedReportService::class)->balanceSheet(2026, 7, ['tenant-a', 'tenant-b']);
            $this->fail('A tenant initialization failure must propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('Tenant initialization failed.', $e->getMessage());
        }
        $this->assertFalse($this->tenancy->initialized);
        $this->assertNull($this->tenancy->tenant);
        $this->assertSame('consolidation_original', DB::getDefaultConnection());
    }

    public function test_explicit_repository_connections_remain_in_effect(): void
    {
        $this->prepareDatabases(true);
        config()->set('accounting.master_data.use_shared_database', false);
        $accounts = $this->mock(AccountRepository::class);
        $accounts->shouldReceive('allOrdered')->times(4)->andReturnUsing(
            fn () => Account::on('consolidation_master')->orderBy('code')->get()
        );
        $categories = $this->mock(AccountCategoryRepository::class)->makePartial();
        $categories->shouldReceive('allOrdered')->times(4)->andReturnUsing(
            fn () => AccountCategory::on('consolidation_master')->orderBy('sequence_no')->get()
        );

        $result = app(ConsolidatedReportService::class)->profitLoss(2026, 7, ['tenant-a', 'tenant-b']);

        $this->assertSame(415.0, $result['report']['net_income']);
        $this->assertFalse(config('accounting.master_data.use_shared_database'));
    }

    public function test_legacy_and_per_tenant_services_and_api_responses_remain_unchanged(): void
    {
        $this->prepareDatabases(false);
        $this->tenancy->initialize(ConsolidationTenant::findOrFail('tenant-a'));
        $reports = app(ReportService::class);
        $before = [
            'profit' => $reports->profitLoss(2026, 7, ['tenant-a', 'tenant-b']),
            'sheet' => $reports->balanceSheet(2026, 7, ['tenant-a', 'tenant-b']),
        ];
        $profitResponse = $this->getJson('/api/accounting/reports/profit-loss?year=2026&month=7')->assertOk()->json();
        $sheetResponse = $this->getJson('/api/accounting/reports/balance-sheet?year=2026&month=7')->assertOk()->json();

        app(ConsolidatedReportService::class)->profitLoss(2026, 7, ['tenant-a', 'tenant-b']);
        app(ConsolidatedReportService::class)->balanceSheet(2026, 7, ['tenant-a', 'tenant-b']);

        $this->assertSame($before['profit'], $reports->profitLoss(2026, 7, ['tenant-a', 'tenant-b']));
        $this->assertSame($before['sheet'], $reports->balanceSheet(2026, 7, ['tenant-a', 'tenant-b']));
        $this->getJson('/api/accounting/reports/profit-loss?year=2026&month=7')->assertExactJson($profitResponse);
        $this->getJson('/api/accounting/reports/balance-sheet?year=2026&month=7')->assertExactJson($sheetResponse);
        $this->assertSame(['tenant_id', 'report'], array_keys($before['profit'][0]));
        $this->assertSame(100.0, $before['profit'][0]['report']['net_income']);
        $this->assertSame(315.0, $before['profit'][1]['report']['net_income']);
        $this->assertSame('tenant-a', $this->tenancy->tenant->getKey());
        $this->assertSame('consolidation_a', DB::getDefaultConnection());
    }

    private function category(array $result, string $code): array
    {
        foreach ($this->nodes($result['report']['data']) as $node) {
            if ($node['category_code'] === $code) {
                return $node;
            }
        }
        $this->fail('Missing category '.$code);
    }

    private function account(array $result, string $code): array
    {
        foreach ($this->nodes($result['report']['data']) as $node) {
            foreach ($node['accounts'] as $account) {
                if ($account['code'] === $code) {
                    return $account;
                }
            }
        }
        $this->fail('Missing account '.$code);
    }

    private function nodes(array $groups): array
    {
        $nodes = [];
        foreach ($groups as $group) {
            foreach ($group as $node) {
                $nodes[] = $node;
                $nodes = array_merge($nodes, $this->nodes([$node['children']]));
            }
        }

        return $nodes;
    }

    private function prepareDatabases(bool $shared): void
    {
        foreach (['consolidation_registry', 'consolidation_master', 'consolidation_a', 'consolidation_b', 'consolidation_original'] as $connection) {
            config()->set('database.connections.'.$connection, [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
            ]);
            DB::purge($connection);
        }
        Schema::connection('consolidation_registry')->create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('slug')->unique();
            $table->string('connection_name');
        });
        foreach (['tenant-a' => ['alpha', 'consolidation_a'], 'tenant-b' => ['beta', 'consolidation_b'], 'original' => ['original', 'consolidation_original']] as $id => [$slug, $connection]) {
            ConsolidationTenant::create(['id' => $id, 'slug' => $slug, 'connection_name' => $connection]);
        }
        config()->set('tenancy.tenant_model', ConsolidationTenant::class);
        config()->set('accounting.master_data.use_shared_database', $shared);
        config()->set('accounting.master_data.connection', 'consolidation_master');

        if ($shared) {
            $this->createMasterData('consolidation_master', 'shared');
        } else {
            $this->createMasterData('consolidation_a', 'a');
            $this->createMasterData('consolidation_b', 'b');
        }
        foreach ([
            'consolidation_a' => ['4000' => 10, '4001' => 100, '4002' => 20, '5001' => 30, '1001' => 200, '2001' => 80, '3001' => 120],
            'consolidation_b' => ['4000' => 5, '4001' => 300, '4101' => 50, '5001' => 40, '1001' => 500, '1002' => 25, '2001' => 125, '3001' => 400],
        ] as $connection => $balances) {
            DB::setDefaultConnection($connection);
            (require $this->migration('2026_01_01_000008_create_acc_monthly_balances_table.php'))->up();
            $master = $shared ? 'consolidation_master' : $connection;
            foreach ($balances as $code => $balance) {
                foreach ([7 => 1, 8 => 2] as $month => $multiplier) {
                    MonthlyBalance::on($connection)->create([
                        'fiscal_year' => 2026, 'fiscal_month' => $month,
                        'account_id' => $this->accountIds[$master][$code],
                        'ending_balance' => $balance * $multiplier,
                    ]);
                }
            }
        }
        DB::setDefaultConnection('testbench');
        $this->tenancy = new class
        {
            public bool $initialized = false;

            public ?Model $tenant = null;

            public array $initializations = [];

            public ?string $failOn = null;

            public function initialize(Model $tenant): void
            {
                $this->initializations[] = $tenant->getKey();
                $this->tenant = $tenant;
                $this->initialized = true;
                DB::setDefaultConnection($tenant->connection_name);
                if ($this->failOn === $tenant->getKey()) {
                    throw new RuntimeException('Tenant initialization failed.');
                }
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
    }

    private function createMasterData(string $connection, string $tenant): void
    {
        DB::setDefaultConnection($connection);
        foreach ([
            '2026_01_01_000001_create_acc_account_categories_table.php',
            '2026_01_01_000002_create_acc_accounts_table.php',
            '2026_06_30_000011_add_parent_id_to_acc_account_categories_table.php',
        ] as $migration) {
            (require $this->migration($migration))->up();
        }
        foreach ([
            'REVENUE' => [null, 'REVENUE'], 'SALES' => ['REVENUE', 'REVENUE'],
            'SERVICES' => ['REVENUE', 'REVENUE'], 'EXPENSE' => [null, 'EXPENSE'],
            'OPERATING' => ['EXPENSE', 'EXPENSE'], 'ASSET' => [null, 'ASSET'],
            'CASH' => ['ASSET', 'ASSET'], 'LIABILITY' => [null, 'LIABILITY'], 'EQUITY' => [null, 'EQUITY'],
        ] as $code => [$parent, $type]) {
            if ($code === 'SERVICES' && $tenant === 'a') {
                continue;
            }
            $category = AccountCategory::on($connection)->create([
                'parent_id' => $parent === null ? null : $this->categoryIds[$connection][$parent],
                'type' => $type, 'category_code' => $code, 'category_name' => $code,
                'report_type' => in_array($type, ['REVENUE', 'EXPENSE'], true) ? 'PL' : 'BS',
            ]);
            $this->categoryIds[$connection][$code] = $category->id;
        }
        foreach ([
            '4000' => 'REVENUE', '4001' => 'SALES', '4002' => 'SALES', '4101' => 'SERVICES',
            '5001' => 'OPERATING', '1001' => 'CASH', '1002' => 'CASH', '2001' => 'LIABILITY', '3001' => 'EQUITY',
        ] as $code => $category) {
            if (($tenant === 'a' && in_array($code, [4101, 1002], true)) || ($tenant === 'b' && $code === 4002)) {
                continue;
            }
            $account = Account::on($connection)->create([
                'category_id' => $this->categoryIds[$connection][$category],
                'code' => (string) $code, 'name' => 'Account '.$code,
            ]);
            $this->accountIds[$connection][$code] = $account->id;
        }
    }

    private function migration(string $filename): string
    {
        return dirname(__DIR__, 2).'/database/migrations/accounting/'.$filename;
    }
}

class ConsolidationTenant extends Model
{
    protected $connection = 'consolidation_registry';

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
