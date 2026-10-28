<?php

namespace ESolution\LaravelAccounting\Tests\Feature;

use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use ESolution\LaravelAccounting\Models\JournalEntry;
use ESolution\LaravelAccounting\Models\JournalEntryDetail;
use ESolution\LaravelAccounting\Repositories\AccountCategoryRepository;
use ESolution\LaravelAccounting\Repositories\AccountRepository;
use ESolution\LaravelAccounting\Services\AccountOpeningBalanceService;
use ESolution\LaravelAccounting\Services\GeneralLedgerService;
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

class GeneralLedgerAccountBreakdownTest extends TestCase
{
    private object $tenancy;

    private bool $shared;

    private array $accounts = [];

    private array $categories = [];

    private int $journalNumber = 0;

    public static function masterModes(): array
    {
        return ['tenant-local master' => [false], 'shared master' => [true]];
    }

    #[DataProvider('masterModes')]
    public function test_debit_normal_breakdown_deduplicates_tenants_and_preserves_opposite_side_and_zero_activity(bool $shared): void
    {
        $this->prepareDatabases($shared);
        DB::setDefaultConnection('breakdown_original');
        $result = $this->breakdown('1001', ['tenant-a', 'alpha', 'tenant-b', 'tenant-c', 'tenant-a']);

        $this->assertSame(['code' => '1001', 'name' => 'Account 1001', 'category' => [
            'category_code' => 'ASSET', 'category_name' => 'Category ASSET', 'type' => 'ASSET',
        ], 'normal_balance' => 'D'], $result['account']);
        $this->assertSame(['start_date' => '2026-07-01', 'end_date' => '2026-07-31'], $result['period']);
        $this->assertSame(['tenant-a', 'tenant-b', 'tenant-c'], $result['tenant_ids']);
        $this->assertSame([
            ['tenant_id' => 'tenant-a', 'total_debit' => 150.0, 'total_credit' => 230.0, 'net_amount' => -80.0, 'balance_side' => 'K'],
            ['tenant_id' => 'tenant-b', 'total_debit' => 400.0, 'total_credit' => 50.0, 'net_amount' => 350.0, 'balance_side' => 'D'],
            ['tenant_id' => 'tenant-c', 'total_debit' => 0.0, 'total_credit' => 0.0, 'net_amount' => 0.0, 'balance_side' => null],
        ], $result['tenants']);
        $this->assertSame(['total_debit' => 550.0, 'total_credit' => 280.0, 'net_amount' => 270.0], $result['totals']);
        $this->assertSame('debit_minus_credit', $result['calculation_basis']);
        $this->assertSame(['tenant-a', 'tenant-b', 'tenant-c'], $this->tenancy->initializations);
        $this->assertSame('breakdown_original', DB::getDefaultConnection());
        $this->assertFalse($this->tenancy->initialized);
        if (! $shared) {
            $this->assertNotSame($this->accounts['breakdown_a']['1001'], $this->accounts['breakdown_b']['1001']);
        }
        $this->assertArrayNotHasKey('opening_balance', $result);
        $this->assertArrayNotHasKey('ending_balance', $result);
    }

    #[DataProvider('masterModes')]
    public function test_credit_normal_basis_is_independent_of_actual_balance_side(bool $shared): void
    {
        $this->prepareDatabases($shared);
        $result = $this->breakdown('4001');
        $this->assertSame('K', $result['account']['normal_balance']);
        $this->assertSame('credit_minus_debit', $result['calculation_basis']);
        $this->assertSame(['tenant_id' => 'tenant-a', 'total_debit' => 300.0, 'total_credit' => 100.0, 'net_amount' => -200.0, 'balance_side' => 'D'], $result['tenants'][0]);
        $this->assertSame(['tenant_id' => 'tenant-b', 'total_debit' => 50.0, 'total_credit' => 500.0, 'net_amount' => 450.0, 'balance_side' => 'K'], $result['tenants'][1]);
        $this->assertNull($result['tenants'][2]['balance_side']);
        $this->assertSame(['total_debit' => 350.0, 'total_credit' => 600.0, 'net_amount' => 250.0], $result['totals']);
    }

    public function test_midmonth_boundaries_include_balanced_activity_without_calling_it_a_side_balance(): void
    {
        $this->prepareDatabases(false);
        $result = app(GeneralLedgerService::class)->getAccountBreakdown('1001', '2026-07-10', '2026-07-20', ['tenant-a', 'tenant-b']);
        $this->assertSame(['tenant_id' => 'tenant-a', 'total_debit' => 30.0, 'total_credit' => 30.0, 'net_amount' => 0.0, 'balance_side' => null], $result['tenants'][0]);
        $this->assertSame(['total_debit' => 30.0, 'total_credit' => 80.0, 'net_amount' => -50.0], $result['totals']);
    }

    public function test_single_day_leap_day_and_cross_year_ranges_are_inclusive(): void
    {
        $this->prepareDatabases(false);
        $this->postMovement('breakdown_a', '1001', '2028-02-28', 1000, 0);
        $this->postMovement('breakdown_a', '1001', '2028-02-29', 10.10, 0);
        $this->postMovement('breakdown_a', '1001', '2028-03-01', 1000, 0);
        $service = app(GeneralLedgerService::class);
        $result = $service->getAccountBreakdown('1001', '2028-02-29', '2028-02-29', ['tenant-a']);
        $this->assertSame(10.10, $result['totals']['net_amount']);
        $this->postMovement('breakdown_a', '1001', '2026-12-31', 10, 0);
        $this->postMovement('breakdown_a', '1001', '2027-01-01', 0, 3);
        $result = $service->getAccountBreakdown('1001', '2026-12-31', '2027-01-01', ['tenant-a']);
        $this->assertSame(['total_debit' => 10.0, 'total_credit' => 3.0, 'net_amount' => 7.0], $result['totals']);
    }

    public function test_missing_accounts_report_all_affected_tenants_after_restoring_context(): void
    {
        $this->prepareDatabases(false);
        Account::on('breakdown_b')->where('code', '1001')->delete();
        Account::on('breakdown_c')->where('code', '1001')->delete();
        $original = BreakdownTenant::findOrFail('original');
        $this->tenancy->initialize($original);
        DB::setDefaultConnection('testbench');
        try {
            $this->breakdown('1001');
            $this->fail('Missing accounts must not appear as confirmed zero activity.');
        } catch (ValidationException $e) {
            $this->assertCount(2, $e->errors()['account_code']);
            $this->assertStringContainsString('tenant-b', $e->errors()['account_code'][0]);
            $this->assertStringContainsString('tenant-c', $e->errors()['account_code'][1]);
        }
        $this->assertSame($original, $this->tenancy->tenant);
        $this->assertSame('testbench', DB::getDefaultConnection());
        try {
            $this->breakdown('MISSING');
            $this->fail('An account absent from every tenant must fail explicitly.');
        } catch (ValidationException $e) {
            $this->assertCount(3, $e->errors()['account_code']);
        }
    }

    public function test_incompatible_normal_balances_are_rejected_after_restoration(): void
    {
        $this->prepareDatabases(false);
        Account::on('breakdown_b')->where('code', '1001')->update(['category_id' => $this->categories['breakdown_b']['REVENUE']]);
        DB::setDefaultConnection('breakdown_original');
        try {
            $this->breakdown('1001');
            $this->fail('Opposing normal-balance definitions cannot share one calculation basis.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('normal_balance', $e->errors());
            $this->assertStringContainsString('tenant-b', $e->getMessage());
        }
        $this->assertFalse($this->tenancy->initialized);
        $this->assertSame('breakdown_original', DB::getDefaultConnection());
    }

    public function test_compatible_normal_balances_allow_different_labels_and_use_first_selected_metadata(): void
    {
        $this->prepareDatabases(false);
        Account::on('breakdown_b')->where('code', '1001')->update(['category_id' => $this->categories['breakdown_b']['EXPENSE'], 'name' => 'Different branch name']);
        $result = $this->breakdown('1001', ['tenant-b', 'tenant-a']);
        $this->assertSame('Different branch name', $result['account']['name']);
        $this->assertSame('EXPENSE', $result['account']['category']['type']);
        $this->assertSame('D', $result['account']['normal_balance']);
        $this->assertSame(270.0, $result['totals']['net_amount']);
    }

    public function test_unknown_category_is_not_silently_assumed_debit_normal(): void
    {
        $this->prepareDatabases(true);
        $this->mock(AccountCategoryRepository::class)->shouldReceive('findById')->andReturnNull();
        try {
            $this->breakdown('1001');
            $this->fail('Unknown classifications must not default to a normal balance.');
        } catch (ValidationException $e) {
            $this->assertCount(3, $e->errors()['normal_balance']);
        }
        $this->assertFalse($this->tenancy->initialized);
        $this->assertSame('testbench', DB::getDefaultConnection());
    }

    public static function invalidInputs(): array
    {
        return [
            'empty code' => ['', '2026-07-01', '2026-07-31'],
            'blank code' => [' ', '2026-07-01', '2026-07-31'],
            'empty start' => ['1001', '', '2026-07-31'],
            'empty end' => ['1001', '2026-07-01', ''],
            'invalid date' => ['1001', '2026-02-30', '2026-07-31'],
            'datetime is not a calendar date' => ['1001', '2026-07-01 00:00:00', '2026-07-31'],
            'reversed dates' => ['1001', '2026-07-31', '2026-07-01'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_does_not_execute_tenants(string $code, string $start, string $end): void
    {
        $this->prepareDatabases(false);
        DB::setDefaultConnection('breakdown_original');
        try {
            app(GeneralLedgerService::class)->getAccountBreakdown($code, $start, $end, ['tenant-a', 'tenant-b']);
            $this->fail('Invalid inputs must fail before execution.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
        $this->assertSame([], $this->tenancy->initializations);
        $this->assertSame('breakdown_original', DB::getDefaultConnection());
    }

    public static function invalidTenants(): array
    {
        return ['empty' => [[]], 'unknown' => [['missing']], 'partial' => [['tenant-a', 'missing']],
            'blank' => [[' ']], 'nested' => [[['tenant-a']]], 'associative' => [['first' => 'tenant-a']]];
    }

    #[DataProvider('invalidTenants')]
    public function test_invalid_tenants_are_validated_as_a_complete_list(array $ids): void
    {
        $this->prepareDatabases(false);
        DB::setDefaultConnection('breakdown_original');
        try {
            $this->breakdown('1001', $ids);
            $this->fail('Invalid tenants must not produce partial totals.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
        $this->assertSame([], $this->tenancy->initializations);
        $this->assertSame('breakdown_original', DB::getDefaultConnection());
    }

    #[DataProvider('masterModes')]
    public function test_success_and_query_failure_restore_active_tenant_and_default_override(bool $shared): void
    {
        $this->prepareDatabases($shared);
        $original = BreakdownTenant::findOrFail('original');
        $this->tenancy->initialize($original);
        DB::setDefaultConnection('testbench');
        $this->breakdown('1001');
        $this->assertSame($original, $this->tenancy->tenant);
        $this->assertSame('testbench', DB::getDefaultConnection());
        Schema::connection('breakdown_b')->drop($this->table('journal_entry_details'));
        try {
            $this->breakdown('1001');
            $this->fail('Query errors must propagate after restoration.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('journal_entry_details', $e->getMessage());
        }
        $this->assertSame($original, $this->tenancy->tenant);
        $this->assertSame('testbench', DB::getDefaultConnection());
    }

    public function test_initialization_failure_restores_inactive_context(): void
    {
        $this->prepareDatabases(false);
        $this->tenancy->failOn = 'tenant-b';
        DB::setDefaultConnection('breakdown_original');
        try {
            $this->breakdown('1001');
            $this->fail('Initialization failure must not fall back to another database.');
        } catch (RuntimeException $e) {
            $this->assertSame('Tenant initialization failed.', $e->getMessage());
        }
        $this->assertFalse($this->tenancy->initialized);
        $this->assertSame('breakdown_original', DB::getDefaultConnection());
    }

    public function test_explicit_master_repository_connections_and_table_prefixes_remain_effective(): void
    {
        $this->prepareDatabases(true, 'ledger_', 'host_');
        config()->set('accounting.master_data.use_shared_database', false);
        $this->mock(AccountRepository::class)->shouldReceive('findByCode')->with('1001')
            ->andReturnUsing(fn ($code) => Account::on('breakdown_master')->where('code', $code)->first());
        $this->mock(AccountCategoryRepository::class)->shouldReceive('findById')
            ->andReturnUsing(fn ($id) => AccountCategory::on('breakdown_master')->find($id));
        $result = $this->breakdown('1001');
        $this->assertSame(270.0, $result['totals']['net_amount']);
        $this->assertSame('testbench', DB::getDefaultConnection());
    }

    public function test_account_codes_retain_leading_zeros(): void
    {
        $this->prepareDatabases(false);
        foreach (['breakdown_a', 'breakdown_b', 'breakdown_c'] as $master) {
            Account::on($master)->where('code', '1001')->update(['code' => '0010']);
        }
        $result = $this->breakdown('0010');
        $this->assertSame('0010', $result['account']['code']);
        $this->assertSame(270.0, $result['totals']['net_amount']);
    }

    public static function otherClassifications(): array
    {
        return ['expense' => ['EXPENSE', 'D', 270.0], 'liability' => ['LIABILITY', 'K', -270.0], 'equity' => ['EQUITY', 'K', -270.0]];
    }

    #[DataProvider('otherClassifications')]
    public function test_other_category_types_use_existing_normal_balance_rules(string $type, string $normalBalance, float $net): void
    {
        $this->prepareDatabases(false);
        foreach (['breakdown_a', 'breakdown_b', 'breakdown_c'] as $master) {
            Account::on($master)->where('code', '1001')->update(['category_id' => $this->categories[$master][$type]]);
        }
        $result = $this->breakdown('1001');
        $this->assertSame($normalBalance, $result['account']['normal_balance']);
        $this->assertSame($net, $result['totals']['net_amount']);
        $this->assertSame('K', $result['tenants'][0]['balance_side']);
        $this->assertSame('D', $result['tenants'][1]['balance_side']);
        $this->assertSame(round(array_sum(array_column($result['tenants'], 'net_amount')), 2), $result['totals']['net_amount']);
    }

    public function test_single_selected_tenant_with_no_activity_returns_confirmed_zero_totals(): void
    {
        $this->prepareDatabases(true);
        $result = $this->breakdown('1001', ['tenant-c']);
        $this->assertSame(['tenant-c'], $result['tenant_ids']);
        $this->assertSame(['total_debit' => 0.0, 'total_credit' => 0.0, 'net_amount' => 0.0], $result['totals']);
        $this->assertNull($result['tenants'][0]['balance_side']);
    }

    private function breakdown(string $code, array $ids = ['tenant-a', 'tenant-b', 'tenant-c']): array
    {
        return app(GeneralLedgerService::class)->getAccountBreakdown($code, '2026-07-01', '2026-07-31', $ids);
    }

    private function prepareDatabases(bool $shared, string $tablePrefix = 'acc_', string $connectionPrefix = ''): void
    {
        $this->shared = $shared;
        config()->set('accounting.table_prefix', $tablePrefix);
        foreach (['breakdown_registry', 'breakdown_master', 'breakdown_a', 'breakdown_b', 'breakdown_c', 'breakdown_original'] as $connection) {
            config()->set('database.connections.'.$connection, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => $connectionPrefix, 'foreign_key_constraints' => true]);
            DB::purge($connection);
        }
        Schema::connection('breakdown_registry')->create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('slug')->unique();
            $table->string('connection_name');
        });
        foreach (['tenant-a' => ['alpha', 'breakdown_a'], 'tenant-b' => ['beta', 'breakdown_b'], 'tenant-c' => ['gamma', 'breakdown_c'], 'original' => ['original', 'breakdown_original']] as $id => [$slug, $connection]) {
            BreakdownTenant::create(['id' => $id, 'slug' => $slug, 'connection_name' => $connection]);
        }
        config()->set('tenancy.tenant_model', BreakdownTenant::class);
        config()->set('accounting.master_data.use_shared_database', $shared);
        config()->set('accounting.master_data.connection', 'breakdown_master');
        foreach ($shared ? ['breakdown_master'] : ['breakdown_a', 'breakdown_b', 'breakdown_c'] as $master) {
            DB::setDefaultConnection($master);
            foreach (['2026_01_01_000001_create_acc_account_categories_table.php', '2026_01_01_000002_create_acc_accounts_table.php', '2026_01_01_000003_create_acc_services_table.php'] as $file) {
                (require $this->migration($file))->up();
            }
            foreach (['ASSET', 'EXPENSE', 'REVENUE', 'LIABILITY', 'EQUITY'] as $type) {
                $category = AccountCategory::on($master)->create(['category_code' => $type, 'category_name' => 'Category '.$type, 'type' => $type, 'report_type' => 'GL']);
                $this->categories[$master][$type] = $category->id;
            }
            foreach (['1001' => 'ASSET', '4001' => 'REVENUE', '6000' => 'EXPENSE', '9999' => 'EQUITY'] as $code => $category) {
                $account = Account::on($master)->create(['code' => (string) $code, 'name' => 'Account '.$code, 'category_id' => $this->categories[$master][$category]]);
                $this->accounts[$master][$code] = $account->id;
            }
        }
        foreach (['breakdown_a', 'breakdown_b', 'breakdown_c'] as $connection) {
            DB::setDefaultConnection($connection);
            foreach (['2026_01_01_000005_create_acc_journal_entries_table.php', '2026_01_01_000006_create_acc_journal_entry_details_table.php', '2026_01_01_000010_add_reversal_fields_to_acc_journal_entries_table.php'] as $file) {
                (require $this->migration($file))->up();
            }
            foreach (['1001', '4001'] as $code) {
                $this->postMovement($connection, $code, '2026-06-30', 100000, 0);
                $this->postMovement($connection, $code, '2026-08-01', 0, 100000);
                $this->postMovement($connection, $code, '2026-07-01', 100000, 0, 'draft');
                $this->postMovement($connection, $code, '2026-07-01', 0, 100000, 'reversed');
                foreach (['OPENING_BALANCE', AccountOpeningBalanceService::SOURCE_TYPE] as $source) {
                    $this->postMovement($connection, $code, '2026-07-01', 10000, 0, 'posted', $source);
                    $this->postMovement($connection, $code, '2026-07-31', 0, 300, 'posted', $source, true);
                }
            }
            $this->postMovement($connection, '6000', '2026-07-15', 123456, 0);
        }
        $this->postMovement('breakdown_a', '1001', '2026-07-01', 120, 0);
        $this->postMovement('breakdown_a', '1001', '2026-07-10', 0, 30, 'posted', 'MANUAL_JOURNAL');
        $this->postMovement('breakdown_a', '1001', '2026-07-12', 30, 0, 'posted', 'MANUAL_JOURNAL', true);
        $this->postMovement('breakdown_a', '1001', '2026-07-31', 0, 200);
        $this->postMovement('breakdown_b', '1001', '2026-07-01', 400, 0);
        $this->postMovement('breakdown_b', '1001', '2026-07-20', 0, 50);
        $this->postMovement('breakdown_a', '4001', '2026-07-01', 0, 100);
        $this->postMovement('breakdown_a', '4001', '2026-07-31', 300, 0, 'posted', 'MANUAL_JOURNAL', true);
        $this->postMovement('breakdown_b', '4001', '2026-07-01', 0, 500);
        $this->postMovement('breakdown_b', '4001', '2026-07-20', 50, 0);
        DB::setDefaultConnection('testbench');
        $this->tenancy = new class
        {
            public bool $initialized = false;

            public ?Model $tenant = null;

            public array $initializations = [];

            public ?string $failOn = null;

            public function initialize(Model $tenant): void
            {
                $this->tenant = $tenant;
                $this->initialized = true;
                $this->initializations[] = $tenant->getKey();
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

    private function postMovement(string $connection, string $code, string $date, float $debit, float $credit, string $status = 'posted', ?string $source = null, bool $reversal = false): void
    {
        $master = $this->shared ? 'breakdown_master' : $connection;
        $journal = JournalEntry::on($connection)->create(['journal_no' => 'BREAKDOWN-'.(++$this->journalNumber), 'trx_date' => $date, 'status' => $status, 'source_type' => $source, 'is_reversal' => $reversal]);
        JournalEntryDetail::on($connection)->create(['journal_entry_id' => $journal->id, 'account_id' => $this->accounts[$master][$code], 'debit' => $debit, 'credit' => $credit]);
        JournalEntryDetail::on($connection)->create(['journal_entry_id' => $journal->id, 'account_id' => $this->accounts[$master]['9999'], 'debit' => $credit, 'credit' => $debit]);
    }

    private function table(string $name): string
    {
        return config('accounting.table_prefix').$name;
    }

    private function migration(string $name): string
    {
        return dirname(__DIR__, 2).'/database/migrations/accounting/'.$name;
    }
}

class BreakdownTenant extends Model
{
    protected $connection = 'breakdown_registry';

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
