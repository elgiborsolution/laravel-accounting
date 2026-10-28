<?php

namespace ESolution\LaravelAccounting\Tests\Feature;

use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use ESolution\LaravelAccounting\Models\MonthlyBalance;
use ESolution\LaravelAccounting\Repositories\AccountCategoryRepository;
use ESolution\LaravelAccounting\Repositories\AccountRepository;
use ESolution\LaravelAccounting\Services\ReportService;
use ESolution\LaravelAccounting\Support\TenantReportRunner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TenantFinancialReportsTest extends TestCase
{
    private ReportTenancyDouble $tenancy;

    private array $categoryIds = [];

    private array $accountIds = [];

    public static function masterModes(): array
    {
        return ['tenant-local master' => [false], 'shared master' => [true]];
    }

    #[DataProvider('masterModes')]
    public function test_apis_return_separate_deduplicated_reports_and_restore_original_connection(bool $shared): void
    {
        $this->prepareDatabases($shared);
        DB::setDefaultConnection('report_original');

        foreach (['profit-loss', 'balance-sheet'] as $endpoint) {
            $this->tenancy->initializations = [];
            $response = $this->getJson('/api/accounting/reports/'.$endpoint.'?'.http_build_query([
                'year' => 2026,
                'month' => 7,
                'tenant_ids' => ['tenant-a', 'tenant-b', 'tenant-a'],
            ]));

            $response->assertOk()
                ->assertJsonPath('status', 200)
                ->assertJsonCount(2, 'data')
                ->assertJsonPath('data.0.tenant_id', 'tenant-a')
                ->assertJsonPath('data.1.tenant_id', 'tenant-b');

            if ($endpoint === 'profit-loss') {
                $response->assertJsonPath('data.0.report.net_income', 70)
                    ->assertJsonPath('data.1.report.net_income', 600)
                    ->assertJsonMissingPath('data.net_income');
            } else {
                $response->assertJsonPath('data.0.report.total_assets', 100)
                    ->assertJsonPath('data.0.report.total_liabilities', 40)
                    ->assertJsonPath('data.0.report.total_equity', 60)
                    ->assertJsonPath('data.1.report.total_assets', 700)
                    ->assertJsonPath('data.1.report.total_liabilities', 200)
                    ->assertJsonPath('data.1.report.total_equity', 500)
                    ->assertJsonMissingPath('data.total_assets');
            }

            $this->assertSame(['tenant-a', 'tenant-b'], $this->tenancy->initializations);
            $this->assertFalse($this->tenancy->initialized);
            $this->assertNull($this->tenancy->tenant);
            $this->assertSame('report_original', DB::getDefaultConnection());
        }
    }

    #[DataProvider('masterModes')]
    public function test_services_use_each_tenants_master_mode_and_period_without_cached_cross_tenant_results(bool $shared): void
    {
        $this->prepareDatabases($shared);
        $service = app(ReportService::class);

        $july = $service->profitLoss(2026, 7, ['tenant-a', 'tenant-b']);
        $august = $service->profitLoss(2026, 8, ['tenant-b', 'tenant-a']);
        $sheets = $service->balanceSheet(2026, 7, ['tenant-a', 'tenant-b']);

        $this->assertSame([70.0, 600.0], array_column(array_column($july, 'report'), 'net_income'));
        $this->assertSame([1200.0, 140.0], array_column(array_column($august, 'report'), 'net_income'));
        $this->assertSame([100.0, 700.0], array_column(array_column($sheets, 'report'), 'total_assets'));
        $this->assertSame($shared ? 'Shared REVENUE' : 'Tenant A REVENUE', $july[0]['report']['data']['revenue'][0]['category_name']);
        $this->assertSame($shared ? 'Shared REVENUE' : 'Tenant B REVENUE', $july[1]['report']['data']['revenue'][0]['category_name']);
        $this->assertSame($shared, config('accounting.master_data.use_shared_database'));
        $this->assertSame('report_master', config('accounting.master_data.connection'));
    }

    #[DataProvider('masterModes')]
    public function test_omitting_tenant_ids_preserves_active_tenant_reports_and_api_envelopes(bool $shared): void
    {
        $this->prepareDatabases($shared);
        $this->tenancy->initialize(ReportTenant::findOrFail('tenant-a'));
        $this->tenancy->initializations = [];

        foreach (['profit-loss' => 'profitLoss', 'balance-sheet' => 'balanceSheet'] as $endpoint => $method) {
            $expected = app(ReportService::class)->{$method}(2026, 7);
            $this->getJson('/api/accounting/reports/'.$endpoint.'?year=2026&month=7')
                ->assertExactJson([
                    'status' => 200,
                    'message' => $endpoint === 'profit-loss'
                        ? 'Profit & Loss retrieved successfully'
                        : 'Balance Sheet retrieved successfully',
                    'data' => json_decode(json_encode($expected), true),
                ])
                ->assertJsonMissingPath('data.tenant_id');
        }

        $this->assertSame([], $this->tenancy->initializations);
        $this->assertSame('report_a', DB::getDefaultConnection());
        $this->assertSame('tenant-a', $this->tenancy->tenant->getKey());
    }

    public function test_legacy_single_database_reports_work_without_tenancy_integration(): void
    {
        $this->prepareDatabases(false);
        config()->set('tenancy.tenant_model', null);
        $this->app->forgetInstance(TenantReportRunner::class);

        $this->getJson('/api/accounting/reports/profit-loss?year=2026&month=7')
            ->assertOk()->assertJsonPath('data.net_income', 7);
        $this->getJson('/api/accounting/reports/balance-sheet?year=2026&month=7')
            ->assertOk()->assertJsonPath('data.total_assets', 10);
        $this->assertSame('testbench', DB::getDefaultConnection());
    }

    public static function invalidLists(): array
    {
        return [
            'empty' => [[]],
            'unknown' => [['missing']],
            'valid followed by unknown' => [['tenant-a', 'missing']],
            'blank item' => [['tenant-a', ' ']],
            'null item' => [[null]],
            'boolean item' => [[false]],
            'nested item' => [[['tenant-a']]],
            'associative array' => [['first' => 'tenant-a']],
        ];
    }

    #[DataProvider('invalidLists')]
    public function test_invalid_lists_are_rejected_before_any_tenant_execution(array $ids): void
    {
        $this->prepareDatabases(true);
        $original = ReportTenant::findOrFail('original');
        $this->tenancy->initialize($original);
        $this->tenancy->initializations = [];

        foreach (['profit-loss', 'balance-sheet'] as $endpoint) {
            // Explicit lists must not initialize the path/header tenant first.
            $this->withHeader('X-Tenant', 'tenant-b')
                ->json('GET', '/api/accounting/tenant-a/reports/'.$endpoint, [
                    'year' => 2026, 'month' => 7, 'tenant_ids' => $ids,
                ])
                ->assertUnprocessable();
        }

        foreach (['profitLoss', 'balanceSheet'] as $method) {
            try {
                app(ReportService::class)->{$method}(2026, 7, $ids);
                $this->fail('Invalid tenant lists must not execute reports.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }

        $this->assertSame([], $this->tenancy->initializations);
        $this->assertSame($original, $this->tenancy->tenant);
        $this->assertSame('report_original', DB::getDefaultConnection());
    }

    public function test_present_null_and_non_array_http_inputs_are_invalid(): void
    {
        $this->prepareDatabases(true);
        foreach ([null, 'tenant-a', false] as $ids) {
            foreach (['profit-loss', 'balance-sheet'] as $endpoint) {
                $this->json('GET', '/api/accounting/reports/'.$endpoint, [
                    'year' => 2026, 'month' => 7, 'tenant_ids' => $ids,
                ])->assertUnprocessable()->assertJsonValidationErrors('tenant_ids');
            }
        }
        $this->assertSame([], $this->tenancy->initializations);
    }

    public function test_route_key_aliases_and_integer_ids_resolve_and_deduplicate_by_tenant(): void
    {
        $this->prepareDatabases(true);
        ReportTenant::create(['id' => '7', 'slug' => 'seven', 'connection_name' => 'report_b']);

        $reports = app(ReportService::class)->profitLoss(2026, 7, ['alpha', 'tenant-a', 7, '7']);

        $this->assertSame(['tenant-a', '7'], array_column($reports, 'tenant_id'));
        $this->assertSame(['tenant-a', '7'], $this->tenancy->initializations);
    }

    #[DataProvider('masterModes')]
    public function test_active_tenant_and_connection_override_are_restored_after_success(bool $shared): void
    {
        $this->prepareDatabases($shared);
        $original = ReportTenant::findOrFail('tenant-b');
        $this->tenancy->initialize($original);
        DB::setDefaultConnection('report_original');

        app(ReportService::class)->profitLoss(2026, 7, ['tenant-a']);
        app(ReportService::class)->balanceSheet(2026, 7, ['tenant-a']);

        $this->assertTrue($this->tenancy->initialized);
        $this->assertSame($original, $this->tenancy->tenant);
        $this->assertSame('report_original', DB::getDefaultConnection());
    }

    #[DataProvider('masterModes')]
    public function test_context_is_restored_when_the_second_tenants_report_query_fails(bool $shared): void
    {
        $this->prepareDatabases($shared);
        Schema::connection('report_b')->drop('acc_monthly_balances');
        $original = ReportTenant::findOrFail('original');
        $this->tenancy->initialize($original);

        foreach (['profitLoss', 'balanceSheet'] as $method) {
            $this->tenancy->initializations = [];
            try {
                app(ReportService::class)->{$method}(2026, 7, ['tenant-a', 'tenant-b']);
                $this->fail('A tenant query failure must propagate.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('acc_monthly_balances', $e->getMessage());
            }
            $this->assertSame(['tenant-a', 'tenant-b', 'original'], $this->tenancy->initializations);
            $this->assertSame($original, $this->tenancy->tenant);
            $this->assertSame('report_original', DB::getDefaultConnection());
        }
    }

    public function test_uninitialized_context_is_restored_after_partial_initialization_failure(): void
    {
        $this->prepareDatabases(true);
        $this->tenancy->failOn = 'tenant-b';
        DB::setDefaultConnection('report_original');

        try {
            app(ReportService::class)->balanceSheet(2026, 7, ['tenant-a', 'tenant-b']);
            $this->fail('Initialization failure must propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('Tenant initialization failed.', $e->getMessage());
        }

        $this->assertFalse($this->tenancy->initialized);
        $this->assertNull($this->tenancy->tenant);
        $this->assertSame('report_original', DB::getDefaultConnection());
    }

    public function test_no_op_initialization_is_rejected_instead_of_reading_the_default_database(): void
    {
        $this->prepareDatabases(true);
        $this->tenancy->ignoreOn = 'tenant-a';

        try {
            app(ReportService::class)->profitLoss(2026, 7, ['tenant-a']);
            $this->fail('A tenant that did not initialize must not run a report.');
        } catch (RuntimeException $e) {
            $this->assertSame('The requested report tenant could not be initialized.', $e->getMessage());
        }
        $this->assertFalse($this->tenancy->initialized);
        $this->assertSame('testbench', DB::getDefaultConnection());
    }

    public function test_missing_tenant_model_is_rejected_without_fallback(): void
    {
        $this->prepareDatabases(true);
        config()->set('tenancy.tenant_model', null);

        $this->getJson('/api/accounting/reports/profit-loss?year=2026&month=7&tenant_ids[]=tenant-a')
            ->assertUnprocessable()->assertJsonValidationErrors('tenant_ids');
        $this->assertSame([], $this->tenancy->initializations);
    }

    public function test_missing_tenancy_integration_is_rejected_without_fallback(): void
    {
        if (function_exists('tenancy')) {
            $this->markTestSkipped('This check requires a host without the optional tenancy helper.');
        }
        $this->prepareDatabases(true);
        $this->app->forgetInstance(TenantReportRunner::class);

        foreach (['profit-loss', 'balance-sheet'] as $endpoint) {
            $this->getJson('/api/accounting/reports/'.$endpoint.'?year=2026&month=7&tenant_ids[]=tenant-a')
                ->assertUnprocessable()->assertJsonValidationErrors('tenant_ids');
        }
        $this->assertSame('testbench', DB::getDefaultConnection());
    }

    public function test_explicit_master_repository_connections_are_preserved(): void
    {
        $this->prepareDatabases(false);
        $accounts = $this->mock(AccountRepository::class);
        $accounts->shouldReceive('allOrdered')->twice()->andReturnUsing(
            fn () => Account::on('testbench')->orderBy('code')->get()
        );
        $categories = $this->mock(AccountCategoryRepository::class)->makePartial();
        $categories->shouldReceive('allOrdered')->twice()->andReturnUsing(
            fn () => AccountCategory::on('testbench')->orderBy('sequence_no')->get()
        );

        $reports = app(ReportService::class)->profitLoss(2026, 7, ['tenant-a', 'tenant-b']);

        $this->assertSame([70.0, 600.0], array_column(array_column($reports, 'report'), 'net_income'));
        $this->assertSame('Central REVENUE', $reports[0]['report']['data']['revenue'][0]['category_name']);
        $this->assertSame('Central REVENUE', $reports[1]['report']['data']['revenue'][0]['category_name']);
    }

    private function prepareDatabases(bool $shared): void
    {
        foreach (['report_registry', 'report_master', 'report_a', 'report_b', 'report_original'] as $connection) {
            config()->set('database.connections.'.$connection, [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
            ]);
            DB::purge($connection);
        }
        Schema::connection('report_registry')->create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('slug')->unique();
            $table->string('connection_name');
        });
        foreach (['tenant-a' => ['alpha', 'report_a'], 'tenant-b' => ['beta', 'report_b'], 'original' => ['original', 'report_original']] as $id => [$slug, $connection]) {
            ReportTenant::create(['id' => $id, 'slug' => $slug, 'connection_name' => $connection]);
        }
        config()->set('tenancy.tenant_model', ReportTenant::class);
        config()->set('accounting.master_data.use_shared_database', $shared);
        config()->set('accounting.master_data.connection', 'report_master');

        foreach (['ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'EXPENSE'] as $type) {
            $this->categoryIds[$type] = (string) Str::uuid();
            $this->accountIds[$type] = (string) Str::uuid();
        }
        if ($shared) {
            $this->createMasterData('report_master', 'Shared');
        } else {
            $this->createMasterData('report_a', 'Tenant A');
            $this->createMasterData('report_b', 'Tenant B');
            $this->createMasterData('testbench', 'Central');
        }

        foreach ([
            'report_a' => [100, 40, 60, 100, 30],
            'report_b' => [700, 200, 500, 800, 200],
            'testbench' => [10, 4, 6, 10, 3],
        ] as $connection => $amounts) {
            DB::setDefaultConnection($connection);
            (require $this->migration('2026_01_01_000008_create_acc_monthly_balances_table.php'))->up();
            foreach (array_values(array_keys($this->accountIds)) as $index => $type) {
                foreach ([7 => 1, 8 => 2] as $month => $multiplier) {
                    MonthlyBalance::on($connection)->create([
                        'fiscal_year' => 2026, 'fiscal_month' => $month,
                        'account_id' => $this->accountIds[$type],
                        'ending_balance' => $amounts[$index] * $multiplier,
                    ]);
                }
            }
        }
        DB::setDefaultConnection('testbench');
        $this->tenancy = new ReportTenancyDouble;
        $this->app->instance(ReportTenancyDouble::class, $this->tenancy);
        $this->app->instance(TenantReportRunner::class, new FinancialReportRunnerDouble);
    }

    private function createMasterData(string $connection, string $label): void
    {
        DB::setDefaultConnection($connection);
        foreach ([
            '2026_01_01_000001_create_acc_account_categories_table.php',
            '2026_01_01_000002_create_acc_accounts_table.php',
            '2026_06_30_000011_add_parent_id_to_acc_account_categories_table.php',
        ] as $migration) {
            (require $this->migration($migration))->up();
        }
        foreach (array_keys($this->categoryIds) as $index => $type) {
            AccountCategory::on($connection)->forceCreate([
                'id' => $this->categoryIds[$type], 'type' => $type,
                'category_code' => $type, 'category_name' => $label.' '.$type,
                'report_type' => in_array($type, ['REVENUE', 'EXPENSE'], true) ? 'PL' : 'BS',
                'sequence_no' => $index,
            ]);
            Account::on($connection)->forceCreate([
                'id' => $this->accountIds[$type], 'category_id' => $this->categoryIds[$type],
                'code' => (string) ($index + 1), 'name' => $label.' '.$type,
            ]);
        }
    }

    private function migration(string $filename): string
    {
        return dirname(__DIR__, 2).'/database/migrations/accounting/'.$filename;
    }
}

// Exercise the real runner with a host-tenancy double without installing a
// global tenancy() helper that would alter unrelated package tests.
class FinancialReportRunnerDouble extends TenantReportRunner
{
    protected function tenancy(): object
    {
        return app(ReportTenancyDouble::class);
    }
}

class ReportTenant extends Model
{
    protected $connection = 'report_registry';

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

class ReportTenancyDouble
{
    public bool $initialized = false;

    public ?ReportTenant $tenant = null;

    public array $initializations = [];

    public ?string $failOn = null;

    public ?string $ignoreOn = null;

    public function initialize(ReportTenant $tenant): void
    {
        $this->initializations[] = $tenant->getKey();
        if ($this->ignoreOn === $tenant->getKey()) {
            return;
        }
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
}
