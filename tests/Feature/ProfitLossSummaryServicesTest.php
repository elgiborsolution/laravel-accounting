<?php

namespace ESolution\LaravelAccounting\Tests\Feature;

use ESolution\LaravelAccounting\Database\Seeders\AccountCategorySeeder;
use ESolution\LaravelAccounting\Database\Seeders\AccountSeeder;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use ESolution\LaravelAccounting\Models\MonthlyBalance;
use ESolution\LaravelAccounting\Repositories\AccountCategoryRepository;
use ESolution\LaravelAccounting\Repositories\AccountRepository;
use ESolution\LaravelAccounting\Services\ConsolidatedReportService;
use ESolution\LaravelAccounting\Services\ProfitLossSummaryService;
use ESolution\LaravelAccounting\Services\ReportService;
use ESolution\LaravelAccounting\Support\TenantReportRunner;
use ESolution\LaravelAccounting\Support\ProfitLossSummaryCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProfitLossSummaryServicesTest extends TestCase
{
    private object $tenancy;

    private bool $shared;

    public static function masterModes(): array
    {
        return ['tenant-local master' => [false], 'shared master' => [true]];
    }

    #[DataProvider('masterModes')]
    public function test_grouped_monthly_summary_returns_only_each_tenants_four_profit_values(bool $shared): void
    {
        $this->prepareDatabases($shared);
        $original = SummaryTenant::findOrFail('original');
        $this->tenancy->initialize($original);
        DB::setDefaultConnection('testbench');
        $service = app(ConsolidatedReportService::class);
        $ids = ['tenant-a', 'alpha', 'tenant-b', 'tenant-a'];
        $before = $service->profitLossSummary(2026, 7, $ids);
        $this->tenancy->initializations = [];
        $result = $service->profitLossSummaryByTenant('2026', '7', $ids);

        $this->assertSame(['tenant_ids', 'period', 'summaries'], array_keys($result));
        $this->assertSame(['tenant-a', 'tenant-b'], $result['tenant_ids']);
        $this->assertSame(['year' => 2026, 'month' => 7], $result['period']);
        $this->assertSame([
            ['tenant_id' => 'tenant-a', 'net_sales' => 1130.0, 'gross_profit' => 730.0, 'operating_profit' => 515.0, 'net_profit' => 547.0],
            ['tenant_id' => 'tenant-b', 'net_sales' => 1970.0, 'gross_profit' => 1070.0, 'operating_profit' => 745.0, 'net_profit' => 812.0],
        ], $result['summaries']);
        $this->assertSame(['tenant-a', 'tenant-b', 'original'], $this->tenancy->initializations);
        $this->assertSame($original, $this->tenancy->tenant);
        $this->assertSame('testbench', DB::getDefaultConnection());
        $this->assertSame($before, $service->profitLossSummary(2026, 7, $ids));

        config()->set('accounting.profit_loss_summary.account_mappings', ['4101' => 'other_operating_income', '5210' => 'income_tax']);
        $mapped = $service->profitLossSummaryByTenant(2026, 7, $ids);
        $this->assertSame(560.0, $mapped['summaries'][0]['operating_profit']);
        $this->assertSame(830.0, $mapped['summaries'][1]['operating_profit']);
        $this->assertSame(547.0, $mapped['summaries'][0]['net_profit']);
        $this->assertSame(812.0, $mapped['summaries'][1]['net_profit']);
    }

    public static function invalidGroupedPeriods(): array
    {
        return ['missing year' => [null, 7], 'zero year' => [0, 7], 'invalid month' => [2026, 13], 'fractional month' => [2026, 7.5]];
    }

    #[DataProvider('invalidGroupedPeriods')]
    public function test_grouped_monthly_periods_are_validated_before_tenant_execution($year, $month): void
    {
        $this->prepareDatabases(false);
        DB::setDefaultConnection('summary_original');
        try {
            app(ConsolidatedReportService::class)->profitLossSummaryByTenant($year, $month, ['tenant-a', 'tenant-b']);
            $this->fail('Invalid periods must not execute tenant reports.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
        $this->assertSame([], $this->tenancy->initializations);
        $this->assertSame('summary_original', DB::getDefaultConnection());
    }

    #[DataProvider('masterModes')]
    public function test_separate_and_consolidated_summaries_use_each_tenants_balances_and_restore_context(bool $shared): void
    {
        $this->prepareDatabases($shared);
        DB::setDefaultConnection('summary_original');
        $ids = ['tenant-a', 'alpha', 'tenant-b', 'tenant-a'];

        $separate = app(ProfitLossSummaryService::class)->summarize(2026, 7, $ids);
        $combined = app(ConsolidatedReportService::class)->profitLossSummary(2026, 7, $ids);

        $this->assertSame(['tenant-a', 'tenant-b'], $separate['tenant_ids']);
        $this->assertSame($separate['tenant_ids'], $combined['tenant_ids']);
        $this->assertSame(['year' => 2026, 'month' => 7], $separate['period']);
        $this->assertSame($separate['period'], $combined['period']);
        $this->assertSame('tenant-a', $separate['summaries'][0]['tenant_id']);
        $this->assertSame('tenant-b', $separate['summaries'][1]['tenant_id']);
        $this->assertSame($this->expected('a'), $separate['summaries'][0]['summary']);
        $this->assertSame($this->expected('b'), $separate['summaries'][1]['summary']);
        foreach ($combined['summary'] as $key => $amount) {
            $this->assertSame(round($separate['summaries'][0]['summary'][$key] + $separate['summaries'][1]['summary'][$key], 2), $amount, $key);
        }
        $this->assertSame(1359.0, $combined['summary']['net_profit']);
        $this->assertSame(['tenant-a', 'tenant-b', 'tenant-a', 'tenant-b'], $this->tenancy->initializations);
        $this->assertFalse($this->tenancy->initialized);
        $this->assertNull($this->tenancy->tenant);
        $this->assertSame('summary_original', DB::getDefaultConnection());
        $this->assertSame($shared, config('accounting.master_data.use_shared_database'));
        if (! $shared) {
            $this->assertNotSame(
                Account::on('summary_a')->where('code', '4001')->value('id'),
                Account::on('summary_b')->where('code', '4001')->value('id')
            );
        }
    }

    #[DataProvider('masterModes')]
    public function test_explicit_other_operating_income_and_income_tax_mappings_apply_identically(bool $shared): void
    {
        $this->prepareDatabases($shared);
        // A partial override keeps all other seeded account/category defaults.
        config()->set('accounting.profit_loss_summary', ['account_mappings' => [
            '4101' => 'other_operating_income', '5210' => 'income_tax',
        ]]);

        $separate = app(ProfitLossSummaryService::class)->summarize(2026, 7, ['tenant-a', 'tenant-b']);
        $combined = app(ConsolidatedReportService::class)->profitLossSummary(2026, 7, ['tenant-a', 'tenant-b']);

        $this->assertSame($this->expected('a', true), $separate['summaries'][0]['summary']);
        $this->assertSame($this->expected('b', true), $separate['summaries'][1]['summary']);
        $this->assertSame(90.0, $combined['summary']['other_operating_income']);
        $this->assertSame(500.0, $combined['summary']['operating_expenses']);
        $this->assertSame(1390.0, $combined['summary']['operating_profit']);
        $this->assertSame(40.0, $combined['summary']['income_tax']);
        $this->assertSame(1359.0, $combined['summary']['net_profit']);
        foreach ($combined['summary'] as $key => $amount) {
            $this->assertSame(round($separate['summaries'][0]['summary'][$key] + $separate['summaries'][1]['summary'][$key], 2), $amount, $key);
        }
    }

    public function test_generic_tax_stays_operating_even_when_its_label_mentions_income_tax(): void
    {
        $this->prepareDatabases(true);
        Account::on('summary_master')->where('code', '5210')->update(['name' => 'Income Tax / Pajak Penghasilan']);

        $result = app(ConsolidatedReportService::class)->profitLossSummary(2026, 7, ['tenant-a', 'tenant-b']);

        $this->assertSame(0.0, $result['summary']['income_tax']);
        $this->assertSame(540.0, $result['summary']['operating_expenses']);
        $this->assertSame(1260.0, $result['summary']['operating_profit']);
    }

    public function test_tax_account_default_and_explicit_override_take_precedence_over_category_rules(): void
    {
        $this->prepareDatabases(false);
        config()->set('accounting.profit_loss_summary.category_mappings.OPERATING_EXPENSE', 'cogs');
        $service = app(ProfitLossSummaryService::class);

        $ordinary = $service->summarize(2026, 7, ['tenant-a'])['summaries'][0]['summary'];
        $this->assertSame(600.0, $ordinary['cogs']);
        $this->assertSame(15.0, $ordinary['operating_expenses']);

        config()->set('accounting.profit_loss_summary.account_mappings.5210', 'income_tax');
        $explicit = $service->summarize(2026, 7, ['tenant-a'])['summaries'][0]['summary'];
        $this->assertSame(0.0, $explicit['operating_expenses']);
        $this->assertSame(15.0, $explicit['income_tax']);
    }

    public function test_per_tenant_classification_does_not_depend_on_translated_labels(): void
    {
        $this->prepareDatabases(false, true);
        $this->assertNotSame(
            Account::on('summary_a')->where('code', '4001')->value('name'),
            Account::on('summary_b')->where('code', '4001')->value('name')
        );

        $result = app(ProfitLossSummaryService::class)->summarize(2026, 7, ['tenant-a', 'tenant-b']);

        $this->assertSame($this->expected('a'), $result['summaries'][0]['summary']);
        $this->assertSame($this->expected('b'), $result['summaries'][1]['summary']);
    }

    public function test_contra_reversals_keep_their_sign_instead_of_being_made_absolute(): void
    {
        $this->prepareDatabases(false);
        foreach (['5701' => 10, '5601' => 5] as $code => $amount) {
            $this->setBalance('summary_a', (string) $code, $amount);
        }

        $separate = app(ProfitLossSummaryService::class)->summarize(2026, 7, ['tenant-a']);
        $combined = app(ConsolidatedReportService::class)->profitLossSummary(2026, 7, ['tenant-a', 'tenant-b']);

        $this->assertSame(-10.0, $separate['summaries'][0]['summary']['sales_returns']);
        $this->assertSame(-5.0, $separate['summaries'][0]['summary']['sales_discounts']);
        $this->assertSame(1215.0, $separate['summaries'][0]['summary']['net_sales']);
        $this->assertSame(632.0, $separate['summaries'][0]['summary']['net_profit']);
        $this->assertSame(1444.0, $combined['summary']['net_profit']);
    }

    public function test_nearest_category_mapping_and_account_override_count_unique_accounts_once(): void
    {
        $this->prepareDatabases(false);
        $parent = AccountCategory::on('summary_a')->where('category_code', 'OTHER_REVENUE')->firstOrFail();
        $category = AccountCategory::on('summary_a')->create([
            'parent_id' => $parent->id, 'category_code' => 'CUSTOM_OPERATING_INCOME',
            'category_name' => 'Pendapatan khusus', 'type' => 'REVENUE', 'report_type' => 'PL',
        ]);
        $account = Account::on('summary_a')->create(['category_id' => $category->id, 'code' => '4900', 'name' => 'Custom account']);
        MonthlyBalance::on('summary_a')->create([
            'fiscal_year' => 2026, 'fiscal_month' => 7, 'account_id' => $account->id, 'ending_balance' => 12,
        ]);
        config()->set('accounting.profit_loss_summary.category_mappings.CUSTOM_OPERATING_INCOME', 'other_operating_income');

        $separate = app(ProfitLossSummaryService::class)->summarize(2026, 7, ['tenant-a', 'tenant-b']);
        $combined = app(ConsolidatedReportService::class)->profitLossSummary(2026, 7, ['tenant-a', 'tenant-b']);
        $this->assertSame(12.0, $separate['summaries'][0]['summary']['other_operating_income']);
        $this->assertSame(12.0, $combined['summary']['other_operating_income']);
        $this->assertSame(40.0, $separate['summaries'][0]['summary']['non_operating_income']);

        config()->set('accounting.profit_loss_summary.account_mappings.4900', 'non_operating_income');
        $separate = app(ProfitLossSummaryService::class)->summarize(2026, 7, ['tenant-a']);
        $combined = app(ConsolidatedReportService::class)->profitLossSummary(2026, 7, ['tenant-a', 'tenant-b']);
        $this->assertSame(0.0, $separate['summaries'][0]['summary']['other_operating_income']);
        $this->assertSame(52.0, $separate['summaries'][0]['summary']['non_operating_income']);
        $this->assertSame(0.0, $combined['summary']['other_operating_income']);
        $this->assertSame(1371.0, $combined['summary']['net_profit']);
    }

    public function test_contra_accounts_in_expense_categories_are_not_negated_twice(): void
    {
        $this->prepareDatabases(false);
        $categoryId = AccountCategory::on('summary_a')->where('category_code', 'OPERATING_EXPENSE')->value('id');
        foreach (['5701' => 50, '5601' => 20] as $code => $amount) {
            Account::on('summary_a')->where('code', $code)->update(['category_id' => $categoryId]);
            $this->setBalance('summary_a', (string) $code, $amount);
        }

        $separate = app(ProfitLossSummaryService::class)->summarize(2026, 7, ['tenant-a']);
        $combined = app(ConsolidatedReportService::class)->profitLossSummary(2026, 7, ['tenant-a']);

        $this->assertSame($this->expected('a'), $separate['summaries'][0]['summary']);
        $this->assertSame($this->expected('a'), $combined['summary']);
    }

    public function test_fractional_amounts_reconcile_between_separate_and_consolidated_summaries(): void
    {
        $this->prepareDatabases(false);
        $this->setBalance('summary_a', '4001', 1000.11);
        $this->setBalance('summary_b', '4001', 2000.22);

        $separate = app(ProfitLossSummaryService::class)->summarize(2026, 7, ['tenant-a', 'tenant-b']);
        $combined = app(ConsolidatedReportService::class)->profitLossSummary(2026, 7, ['tenant-a', 'tenant-b']);

        $this->assertSame(547.11, $separate['summaries'][0]['summary']['net_profit']);
        $this->assertSame(812.22, $separate['summaries'][1]['summary']['net_profit']);
        $this->assertSame(3300.33, $combined['summary']['sales_revenue']);
        $this->assertSame(1359.33, $combined['summary']['net_profit']);
    }

    public function test_duplicate_report_accounts_are_rejected_instead_of_counted_twice(): void
    {
        $calculator = app(ProfitLossSummaryCalculator::class);
        $account = ['code' => '4001', 'balance' => 100];
        $report = ['data' => ['revenue' => [[
            'category_code' => 'SALES_REVENUE', 'type' => 'REVENUE',
            'accounts' => [$account, $account], 'children' => [],
        ]], 'expense' => []]];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Account [4001] occurs more than once');
        $calculator->calculate($report, $calculator->mappings());
    }

    public function test_periods_remain_separate_and_service_singletons_read_current_mapping_overrides(): void
    {
        $this->prepareDatabases(true);
        $service = app(ConsolidatedReportService::class);

        $july = $service->profitLossSummary(2026, 7, ['tenant-a', 'tenant-b']);
        $august = $service->profitLossSummary(2026, 8, ['tenant-a', 'tenant-b']);
        $this->assertSame(1359.0, $july['summary']['net_profit']);
        $this->assertSame(2718.0, $august['summary']['net_profit']);
        $this->assertSame(['year' => 2026, 'month' => 8], $august['period']);

        config()->set('accounting.profit_loss_summary.account_mappings.5210', 'income_tax');
        $reclassified = $service->profitLossSummary(2026, 7, ['tenant-a', 'tenant-b']);
        $this->assertSame(40.0, $reclassified['summary']['income_tax']);
        $this->assertSame(1359.0, $reclassified['summary']['net_profit']);
    }

    public static function invalidLists(): array
    {
        return ['empty' => [[]], 'missing' => [['missing']], 'valid then missing' => [['tenant-a', 'missing']],
            'blank' => [[' ']], 'nested' => [[['tenant-a']]], 'non-list' => [['first' => 'tenant-a']]];
    }

    #[DataProvider('invalidLists')]
    public function test_invalid_tenants_are_rejected_before_any_execution(array $ids): void
    {
        $this->prepareDatabases(false);
        foreach ($this->summaryCalls() as $summary) {
            try {
                $summary(2026, 7, $ids);
                $this->fail('Invalid tenant lists must not execute reports.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertSame([], $this->tenancy->initializations);
        $this->assertSame('testbench', DB::getDefaultConnection());
    }

    #[DataProvider('masterModes')]
    public function test_query_failure_restores_original_tenant_and_default_connection_override(bool $shared): void
    {
        $this->prepareDatabases($shared);
        Schema::connection('summary_b')->drop('acc_monthly_balances');
        $original = SummaryTenant::findOrFail('original');
        $this->tenancy->initialize($original);
        DB::setDefaultConnection('testbench');
        foreach ($this->summaryCalls() as $summary) {
            try {
                $summary(2026, 7, ['tenant-a', 'tenant-b']);
                $this->fail('A failed tenant must not be skipped.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('acc_monthly_balances', $e->getMessage());
            }
            $this->assertSame($original, $this->tenancy->tenant);
            $this->assertSame('testbench', DB::getDefaultConnection());
        }
    }

    public function test_initialization_failure_restores_an_inactive_context(): void
    {
        $this->prepareDatabases(true);
        $this->tenancy->failOn = 'tenant-b';
        DB::setDefaultConnection('summary_original');
        foreach ($this->summaryCalls() as $summary) {
            try {
                $summary(2026, 7, ['tenant-a', 'tenant-b']);
                $this->fail('Tenant initialization failure must propagate.');
            } catch (RuntimeException $e) {
                $this->assertSame('Tenant initialization failed.', $e->getMessage());
            }
            $this->assertFalse($this->tenancy->initialized);
            $this->assertNull($this->tenancy->tenant);
            $this->assertSame('summary_original', DB::getDefaultConnection());
        }
    }

    public function test_unmapped_nonzero_accounts_fail_explicitly_after_context_has_been_restored(): void
    {
        $this->prepareDatabases(false);
        $category = AccountCategory::on('summary_a')->where('category_code', 'REVENUE')->firstOrFail();
        $account = Account::on('summary_a')->create(['category_id' => $category->id, 'code' => '4999', 'name' => 'Unmapped']);
        MonthlyBalance::on('summary_a')->create([
            'fiscal_year' => 2026, 'fiscal_month' => 7, 'account_id' => $account->id, 'ending_balance' => 5,
        ]);
        $original = SummaryTenant::findOrFail('original');
        $this->tenancy->initialize($original);
        foreach ($this->summaryCalls() as $summary) {
            try {
                $summary(2026, 7, ['tenant-a', 'tenant-b']);
                $this->fail('Nonzero accounts must not be silently omitted.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('[4999]', $e->getMessage());
                $this->assertArrayHasKey('summary', $e->errors());
            }
            $this->assertSame($original, $this->tenancy->tenant);
            $this->assertSame('summary_original', DB::getDefaultConnection());
        }
    }

    public function test_invalid_classification_configuration_is_rejected_before_tenant_execution(): void
    {
        $this->prepareDatabases(false);
        config()->set('accounting.profit_loss_summary.account_mappings.5210', 'Translated tax label');
        foreach ($this->summaryCalls() as $summary) {
            try {
                $summary(2026, 7, ['tenant-a']);
                $this->fail('Configuration must use explicit component identifiers.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('summary_mappings', $e->errors());
            }
        }
        $this->assertSame([], $this->tenancy->initializations);
    }

    public function test_explicit_repository_connections_are_preserved(): void
    {
        $this->prepareDatabases(true);
        config()->set('accounting.master_data.use_shared_database', false);
        $accounts = $this->mock(AccountRepository::class);
        $accounts->shouldReceive('allOrdered')->andReturnUsing(fn () => Account::on('summary_master')->orderBy('code')->get());
        $categories = $this->mock(AccountCategoryRepository::class)->makePartial();
        $categories->shouldReceive('allOrdered')->andReturnUsing(fn () => AccountCategory::on('summary_master')->orderBy('sequence_no')->get());

        $separate = app(ProfitLossSummaryService::class)->summarize(2026, 7, ['tenant-a', 'tenant-b']);
        $combined = app(ConsolidatedReportService::class)->profitLossSummary(2026, 7, ['tenant-a', 'tenant-b']);

        $this->assertSame($this->expected('a'), $separate['summaries'][0]['summary']);
        $this->assertSame(1359.0, $combined['summary']['net_profit']);
        $this->assertFalse(config('accounting.master_data.use_shared_database'));
    }

    public function test_existing_report_payloads_and_api_responses_remain_unchanged(): void
    {
        $this->prepareDatabases(false);
        $original = SummaryTenant::findOrFail('tenant-a');
        $this->tenancy->initialize($original);
        $reports = app(ReportService::class);
        $consolidated = app(ConsolidatedReportService::class);
        $legacy = $reports->profitLoss(2026, 7);
        $perTenant = $reports->profitLoss(2026, 7, ['tenant-a', 'tenant-b']);
        $merged = $consolidated->profitLoss(2026, 7, ['tenant-a', 'tenant-b']);
        $response = $this->getJson('/api/accounting/reports/profit-loss?year=2026&month=7')->assertOk()->json();

        foreach ($this->summaryCalls() as $summary) {
            $summary(2026, 7, ['tenant-a', 'tenant-b']);
        }

        $this->assertSame($legacy, $reports->profitLoss(2026, 7));
        $this->assertSame($perTenant, $reports->profitLoss(2026, 7, ['tenant-a', 'tenant-b']));
        $this->assertSame($merged, $consolidated->profitLoss(2026, 7, ['tenant-a', 'tenant-b']));
        $this->getJson('/api/accounting/reports/profit-loss?year=2026&month=7')->assertExactJson($response);
        $this->assertSame($original, $this->tenancy->tenant);
        $this->assertSame('summary_a', DB::getDefaultConnection());
    }

    private function expected(string $tenant, bool $explicit = false): array
    {
        $a = $tenant === 'a';

        return [
            'sales_revenue' => $a ? 1200.0 : 2100.0,
            'sales_returns' => $a ? 50.0 : 100.0,
            'sales_discounts' => $a ? 20.0 : 30.0,
            'cogs' => $a ? 400.0 : 900.0,
            'other_operating_income' => $explicit ? ($a ? 30.0 : 60.0) : 0.0,
            'operating_expenses' => $explicit ? ($a ? 200.0 : 300.0) : ($a ? 215.0 : 325.0),
            'non_operating_income' => $explicit ? ($a ? 10.0 : 20.0) : ($a ? 40.0 : 80.0),
            'non_operating_expenses' => $a ? 8.0 : 13.0,
            'income_tax' => $explicit ? ($a ? 15.0 : 25.0) : 0.0,
            'net_sales' => $a ? 1130.0 : 1970.0,
            'gross_profit' => $a ? 730.0 : 1070.0,
            'operating_profit' => $explicit ? ($a ? 560.0 : 830.0) : ($a ? 515.0 : 745.0),
            'net_profit' => $a ? 547.0 : 812.0,
        ];
    }

    private function summaryCalls(): array
    {
        return [[app(ProfitLossSummaryService::class), 'summarize'], [app(ConsolidatedReportService::class), 'profitLossSummary'],
            [app(ConsolidatedReportService::class), 'profitLossSummaryByTenant']];
    }

    private function setBalance(string $connection, string $code, float $amount): void
    {
        $master = $this->shared ? 'summary_master' : $connection;
        $accountId = Account::on($master)->where('code', $code)->value('id');
        MonthlyBalance::on($connection)->where('account_id', $accountId)->where('fiscal_month', 7)->update(['ending_balance' => $amount]);
    }

    private function prepareDatabases(bool $shared, bool $differentLanguages = false): void
    {
        $this->shared = $shared;
        foreach (['summary_registry', 'summary_master', 'summary_a', 'summary_b', 'summary_original'] as $connection) {
            config()->set('database.connections.'.$connection, [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
            ]);
            DB::purge($connection);
        }
        Schema::connection('summary_registry')->create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('slug')->unique();
            $table->string('connection_name');
        });
        foreach (['tenant-a' => ['alpha', 'summary_a'], 'tenant-b' => ['beta', 'summary_b'], 'original' => ['original', 'summary_original']] as $id => [$slug, $connection]) {
            SummaryTenant::create(['id' => $id, 'slug' => $slug, 'connection_name' => $connection]);
        }
        config()->set('tenancy.tenant_model', SummaryTenant::class);
        config()->set('accounting.master_data.use_shared_database', $shared);
        config()->set('accounting.master_data.connection', 'summary_master');
        foreach ($shared ? ['summary_master'] : ['summary_a', 'summary_b'] as $master) {
            DB::setDefaultConnection($master);
            foreach ([
                '2026_01_01_000001_create_acc_account_categories_table.php',
                '2026_01_01_000002_create_acc_accounts_table.php',
                '2026_06_30_000011_add_parent_id_to_acc_account_categories_table.php',
            ] as $migration) {
                (require $this->migration($migration))->up();
            }
            config()->set('accounting.default_language', $differentLanguages && $master === 'summary_b' ? 'en' : 'id');
            app(AccountCategorySeeder::class)->run();
            app(AccountSeeder::class)->run();
        }
        foreach ([
            'summary_a' => ['4001' => 1000, '4020' => 200, '5701' => -50, '5601' => -20, '5001' => 400,
                '4101' => 30, '4040' => 10, '5101' => 200, '5210' => 15, '5300' => 5, '5310' => 3],
            'summary_b' => ['4001' => 2000, '4020' => 100, '5701' => -100, '5601' => -30, '5001' => 900,
                '4101' => 60, '4040' => 20, '5101' => 300, '5210' => 25, '5300' => 8, '5310' => 5],
        ] as $connection => $balances) {
            DB::setDefaultConnection($connection);
            (require $this->migration('2026_01_01_000008_create_acc_monthly_balances_table.php'))->up();
            $master = $shared ? 'summary_master' : $connection;
            $accounts = Account::on($master)->get()->keyBy('code');
            foreach ($balances as $code => $amount) {
                foreach ([7 => 1, 8 => 2] as $month => $multiplier) {
                    MonthlyBalance::on($connection)->create([
                        'fiscal_year' => 2026, 'fiscal_month' => $month,
                        'account_id' => $accounts->get($code)->id, 'ending_balance' => $amount * $multiplier,
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

    private function migration(string $filename): string
    {
        return dirname(__DIR__, 2).'/database/migrations/accounting/'.$filename;
    }
}

class SummaryTenant extends Model
{
    protected $connection = 'summary_registry';

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
