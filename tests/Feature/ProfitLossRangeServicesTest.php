<?php

namespace ESolution\LaravelAccounting\Tests\Feature;

use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use ESolution\LaravelAccounting\Models\FiscalPeriod;
use ESolution\LaravelAccounting\Models\JournalEntry;
use ESolution\LaravelAccounting\Models\JournalEntryDetail;
use ESolution\LaravelAccounting\Models\MonthlyBalance;
use ESolution\LaravelAccounting\Repositories\AccountCategoryRepository;
use ESolution\LaravelAccounting\Repositories\AccountRepository;
use ESolution\LaravelAccounting\Services\AccountOpeningBalanceService;
use ESolution\LaravelAccounting\Services\ConsolidatedReportService;
use ESolution\LaravelAccounting\Services\ProfitLossSummaryService;
use ESolution\LaravelAccounting\Services\ReportService;
use ESolution\LaravelAccounting\Support\AccountingConnectionResolver;
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

class ProfitLossRangeServicesTest extends TestCase
{
    private object $tenancy;

    private bool $shared;

    private array $accountIds = [];

    private int $journalNumber = 0;

    public static function masterModes(): array
    {
        return ['tenant-local master' => [false], 'shared master' => [true]];
    }

    #[DataProvider('masterModes')]
    public function test_cross_year_reports_and_summaries_are_separate_or_consolidated_as_requested(bool $shared): void
    {
        $this->prepareDatabases($shared);
        DB::setDefaultConnection('range_original');
        $ids = ['tenant-a', 'alpha', 'tenant-b', 'tenant-a'];
        $reports = app(ReportService::class)->profitLossRange($this->range(), $ids);
        $summaries = app(ProfitLossSummaryService::class)->summarizeRange($this->range(), $ids);
        $combined = app(ConsolidatedReportService::class)->profitLossRange($this->range(), $ids);
        $summary = app(ConsolidatedReportService::class)->profitLossSummaryRange($this->range(), $ids);

        foreach ([$reports, $summaries, $combined, $summary] as $result) {
            $this->assertSame(['tenant-a', 'tenant-b'], $result['tenant_ids']);
            $this->assertSame($this->period(), $result['period']);
        }
        $this->assertSame(['tenant-a', 'tenant-b'], array_column($reports['reports'], 'tenant_id'));
        $this->assertSame(560.0, $reports['reports'][0]['report']['net_income']);
        $this->assertSame(1110.0, $reports['reports'][1]['report']['net_income']);
        $this->assertSame(1670.0, $combined['report']['net_income']);
        $this->assertSame(1400.0, $this->account($reports['reports'][0]['report'], '4001')['balance']);
        $this->assertSame(4200.0, $this->account($combined['report'], '4001')['balance']);
        $this->assertSame(40.0, $this->account($combined['report'], '4002')['balance']);
        $this->assertSame(70.0, $this->account($combined['report'], '4020')['balance']);
        $this->assertArrayNotHasKey('id', $this->account($combined['report'], '4001'));
        $this->assertSame('SALES_REVENUE', $combined['report']['data']['revenue'][0]['children'][1]['category_code']);
        $this->assertSame($this->expectedA(), $summaries['summaries'][0]['summary']);
        $this->assertSame(1110.0, $summaries['summaries'][1]['summary']['net_profit']);
        foreach ($summary['summary'] as $key => $amount) {
            $this->assertSame(round($summaries['summaries'][0]['summary'][$key] + $summaries['summaries'][1]['summary'][$key], 2), $amount, $key);
        }
        $this->assertSame(1670.0, $summary['summary']['net_profit']);
        $this->assertSame(array_merge(...array_fill(0, 4, ['tenant-a', 'tenant-b'])), $this->tenancy->initializations);
        $this->assertFalse($this->tenancy->initialized);
        $this->assertSame('range_original', DB::getDefaultConnection());
        if (! $shared) {
            $this->assertNotSame($this->accountIds['range_a']['4001'], $this->accountIds['range_b']['4001']);
        }
    }

    public function test_active_connection_needs_no_tenancy_and_does_not_follow_request_headers(): void
    {
        $this->prepareDatabases(false);
        config()->set('tenancy.tenant_model', null);
        request()->headers->set('X-Tenant', 'tenant-b');
        $reports = app(ReportService::class);
        $summaries = app(ProfitLossSummaryService::class);
        DB::setDefaultConnection('range_a');
        $report = $reports->profitLossRange($this->range());
        $summary = $summaries->summarizeRange($this->range());
        $this->assertSame(['period', 'report'], array_keys($report));
        $this->assertSame(['period', 'summary'], array_keys($summary));
        $this->assertSame(560.0, $report['report']['net_income']);
        $this->assertSame($this->expectedA(), $summary['summary']);
        $this->assertSame('range_a', DB::getDefaultConnection());
        DB::setDefaultConnection('range_b');
        $this->assertSame(1110.0, $reports->profitLossRange($this->range())['report']['net_income']);
        $this->assertSame([], $this->tenancy->initializations);
    }

    public static function periods(): array
    {
        return [
            'one month including first and final dates' => [2026, 11, 2026, 11, 340.0],
            'second month' => [2026, 12, 2026, 12, 190.0],
            'unclosed cross-year month with signed reversals' => [2027, 1, 2027, 1, 30.0],
            'multiple months' => [2026, 11, 2026, 12, 530.0],
            'cross-year range' => [2026, 11, 2027, 1, 560.0],
            'empty future month' => [2027, 3, 2027, 3, 0.0],
        ];
    }

    #[DataProvider('periods')]
    public function test_inclusive_range_boundaries_exclusions_and_empty_periods(int $startYear, int $startMonth, int $endYear, int $endMonth, float $expected): void
    {
        $this->prepareDatabases(false);
        DB::setDefaultConnection('range_a');
        $range = ['start_year' => $startYear, 'start_month' => $startMonth, 'end_year' => $endYear, 'end_month' => $endMonth];
        $report = app(ReportService::class)->profitLossRange($range);
        $summary = app(ProfitLossSummaryService::class)->summarizeRange($range);
        $this->assertSame($expected, $report['report']['net_income']);
        $this->assertSame($expected, $summary['summary']['net_profit']);
        $this->assertSame($range, array_diff_key($report['period'], ['year' => true, 'month' => true]));
        if ($expected === 0.0) {
            $this->assertSame(0.0, $this->account($report['report'], '4001')['balance']);
        }
    }

    public function test_leap_day_boundaries_and_fractional_movements(): void
    {
        $this->prepareDatabases(false);
        $this->postMovement('range_a', '4001', '2028-01-31', 0, 9999);
        $this->postMovement('range_a', '4001', '2028-02-01', 0, 10.10);
        $this->postMovement('range_a', '4001', '2028-02-29', 0, 20.20);
        $this->postMovement('range_a', '4001', '2028-03-01', 0, 9999);
        $this->postMovement('range_b', '4001', '2028-02-29', 0, 5.05);
        $range = ['start_year' => 2028, 'start_month' => 2, 'end_year' => 2028, 'end_month' => 2];
        $result = app(ConsolidatedReportService::class)->profitLossSummaryRange($range, ['tenant-a', 'tenant-b']);
        $this->assertSame(35.35, $result['summary']['net_profit']);
        $this->assertSame(35.35, $result['summary']['sales_revenue']);
    }

    #[DataProvider('masterModes')]
    public function test_ranges_do_not_read_snapshots_or_fiscal_periods_and_monthly_results_stay_unchanged(bool $shared): void
    {
        $this->prepareDatabases($shared);
        DB::setDefaultConnection('range_a');
        $reports = app(ReportService::class);
        $consolidated = app(ConsolidatedReportService::class);
        $summaries = app(ProfitLossSummaryService::class);
        $before = [
            $reports->profitLoss(2026, 11), $reports->balanceSheet(2026, 11),
            $reports->profitLoss(2026, 11, ['tenant-a', 'tenant-b']),
            $consolidated->profitLoss(2026, 11, ['tenant-a', 'tenant-b']),
            $consolidated->balanceSheet(2026, 11, ['tenant-a', 'tenant-b']),
            $summaries->summarize(2026, 11, ['tenant-a', 'tenant-b']),
            $consolidated->profitLossSummary(2026, 11, ['tenant-a', 'tenant-b']),
        ];
        $rangeReport = $reports->profitLossRange($this->range());
        $consolidated->profitLossRange($this->range(), ['tenant-a', 'tenant-b']);
        $summaries->summarizeRange($this->range(), ['tenant-a', 'tenant-b']);
        $consolidated->profitLossSummaryRange($this->range(), ['tenant-a', 'tenant-b']);
        $this->assertNotSame($before[0]['net_income'], $rangeReport['report']['net_income']);
        $this->assertSame($before, [
            $reports->profitLoss(2026, 11), $reports->balanceSheet(2026, 11),
            $reports->profitLoss(2026, 11, ['tenant-a', 'tenant-b']),
            $consolidated->profitLoss(2026, 11, ['tenant-a', 'tenant-b']),
            $consolidated->balanceSheet(2026, 11, ['tenant-a', 'tenant-b']),
            $summaries->summarize(2026, 11, ['tenant-a', 'tenant-b']),
            $consolidated->profitLossSummary(2026, 11, ['tenant-a', 'tenant-b']),
        ]);
        foreach (['range_a', 'range_b'] as $connection) {
            Schema::connection($connection)->drop($this->table('monthly_balances'));
            Schema::connection($connection)->drop($this->table('fiscal_periods'));
        }
        $this->assertSame(560.0, $reports->profitLossRange($this->range())['report']['net_income']);
        $this->assertSame(1670.0, $consolidated->profitLossSummaryRange($this->range(), ['tenant-a', 'tenant-b'])['summary']['net_profit']);
    }

    public static function invalidRanges(): array
    {
        $valid = ['start_year' => 2026, 'start_month' => 11, 'end_year' => 2027, 'end_month' => 1];

        return [
            'empty' => [[]], 'incomplete' => [['start_year' => 2026]],
            'null field' => [array_replace($valid, ['start_month' => null])],
            'invalid month' => [array_replace($valid, ['end_month' => 13])],
            'zero month' => [array_replace($valid, ['start_month' => 0])],
            'zero year' => [array_replace($valid, ['start_year' => 0])],
            'negative year' => [array_replace($valid, ['end_year' => -1])],
            'fractional year' => [array_replace($valid, ['start_year' => 2026.5])],
            'nested field' => [array_replace($valid, ['start_month' => [11]])],
            'reversed years' => [array_replace($valid, ['start_year' => 2028])],
            'reversed months' => [array_replace($valid, ['end_year' => 2026, 'end_month' => 10])],
        ];
    }

    #[DataProvider('invalidRanges')]
    public function test_all_range_methods_validate_before_tenant_execution(array $range): void
    {
        $this->prepareDatabases(false);
        DB::setDefaultConnection('range_original');
        foreach ($this->calls() as [$service, $method]) {
            try {
                $service->{$method}($range, ['tenant-a', 'tenant-b']);
                $this->fail('Invalid ranges must not execute tenant reports.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertSame([], $this->tenancy->initializations);
        $this->assertSame('range_original', DB::getDefaultConnection());
    }

    public static function invalidTenants(): array
    {
        return ['empty' => [[]], 'missing' => [['missing']], 'partial list' => [['tenant-a', 'missing']],
            'blank' => [[' ']], 'nested' => [[['tenant-a']]], 'associative' => [['first' => 'tenant-a']]];
    }

    #[DataProvider('invalidTenants')]
    public function test_all_range_methods_validate_all_tenants_before_execution(array $ids): void
    {
        $this->prepareDatabases(false);
        DB::setDefaultConnection('range_original');
        foreach ($this->calls() as [$service, $method]) {
            try {
                $service->{$method}($this->range(), $ids);
                $this->fail('Invalid tenants must not produce partial results.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertSame([], $this->tenancy->initializations);
        $this->assertSame('range_original', DB::getDefaultConnection());
    }

    #[DataProvider('masterModes')]
    public function test_success_and_query_failure_restore_original_tenant_and_connection_override(bool $shared): void
    {
        $this->prepareDatabases($shared);
        $original = RangeTenant::findOrFail('original');
        $this->tenancy->initialize($original);
        DB::setDefaultConnection('testbench');
        foreach ($this->calls() as [$service, $method]) {
            $service->{$method}($this->range(), ['tenant-a', 'tenant-b']);
            $this->assertSame($original, $this->tenancy->tenant);
            $this->assertSame('testbench', DB::getDefaultConnection());
        }
        Schema::connection('range_b')->drop($this->table('journal_entry_details'));
        foreach ($this->calls() as [$service, $method]) {
            try {
                $service->{$method}($this->range(), ['tenant-a', 'tenant-b']);
                $this->fail('Failed source queries must propagate.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('journal_entry_details', $e->getMessage());
            }
            $this->assertSame($original, $this->tenancy->tenant);
            $this->assertSame('testbench', DB::getDefaultConnection());
        }
    }

    public function test_initialization_failure_restores_inactive_context_for_every_method(): void
    {
        $this->prepareDatabases(false);
        $this->tenancy->failOn = 'tenant-b';
        DB::setDefaultConnection('range_original');
        foreach ($this->calls() as [$service, $method]) {
            try {
                $service->{$method}($this->range(), ['tenant-a', 'tenant-b']);
                $this->fail('Failed initialization must propagate.');
            } catch (RuntimeException $e) {
                $this->assertSame('Tenant initialization failed.', $e->getMessage());
            }
            $this->assertFalse($this->tenancy->initialized);
            $this->assertSame('range_original', DB::getDefaultConnection());
        }
    }

    public function test_mapping_overrides_and_classification_failures_preserve_context(): void
    {
        $this->prepareDatabases(false);
        DB::setDefaultConnection('range_original');
        config()->set('accounting.profit_loss_summary.account_mappings', ['4101' => 'other_operating_income', '5210' => 'income_tax']);
        $separate = app(ProfitLossSummaryService::class)->summarizeRange($this->range(), ['tenant-a', 'tenant-b']);
        $combined = app(ConsolidatedReportService::class)->profitLossSummaryRange($this->range(), ['tenant-a', 'tenant-b']);
        $this->assertSame(30.0, $separate['summaries'][0]['summary']['other_operating_income']);
        $this->assertSame(15.0, $separate['summaries'][0]['summary']['income_tax']);
        $this->assertSame(90.0, $combined['summary']['other_operating_income']);
        $this->assertSame(45.0, $combined['summary']['income_tax']);
        $this->assertSame(1670.0, $combined['summary']['net_profit']);
        foreach (['range_a', 'range_b'] as $connection) {
            AccountCategory::on($connection)->where('category_code', 'SALES_REVENUE')->update(['category_code' => 'UNMAPPED']);
        }
        foreach ([[app(ProfitLossSummaryService::class), 'summarizeRange'], [app(ConsolidatedReportService::class), 'profitLossSummaryRange']] as [$service, $method]) {
            try {
                $service->{$method}($this->range(), ['tenant-a', 'tenant-b']);
                $this->fail('Unmapped movements must fail after restoration.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('summary', $e->errors());
            }
            $this->assertFalse($this->tenancy->initialized);
            $this->assertSame('range_original', DB::getDefaultConnection());
        }
    }

    public function test_incompatible_codes_are_rejected_after_restoration(): void
    {
        $this->prepareDatabases(false);
        Account::on('range_b')->where('code', '4001')->update(['name' => 'Incompatible account']);
        DB::setDefaultConnection('range_original');
        foreach (['profitLossRange', 'profitLossSummaryRange'] as $method) {
            try {
                app(ConsolidatedReportService::class)->{$method}($this->range(), ['tenant-a', 'tenant-b']);
                $this->fail('Incompatible definitions must not be combined.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('consolidation', $e->errors());
            }
            $this->assertSame('range_original', DB::getDefaultConnection());
        }
    }

    public function test_package_and_connection_table_prefixes_are_respected(): void
    {
        $this->prepareDatabases(true, 'ledger_', 'host_');
        $result = app(ConsolidatedReportService::class)->profitLossSummaryRange($this->range(), ['tenant-a', 'tenant-b']);
        $this->assertSame(1670.0, $result['summary']['net_profit']);
    }

    public function test_explicit_master_repository_and_transaction_connection_overrides_are_respected(): void
    {
        $this->prepareDatabases(true);
        config()->set('accounting.master_data.use_shared_database', false);
        $this->mock(AccountRepository::class)->shouldReceive('allOrdered')->andReturnUsing(fn () => Account::on('range_master')->orderBy('code')->get());
        $this->mock(AccountCategoryRepository::class)->makePartial()->shouldReceive('allOrdered')->andReturnUsing(fn () => AccountCategory::on('range_master')->orderBy('sequence_no')->get());
        DB::setDefaultConnection('range_original');
        $combined = app(ConsolidatedReportService::class)->profitLossSummaryRange($this->range(), ['tenant-a', 'tenant-b']);
        $this->assertSame(1670.0, $combined['summary']['net_profit']);
        $this->assertSame('range_original', DB::getDefaultConnection());
        $this->mock(AccountingConnectionResolver::class)->makePartial()
            ->shouldReceive('resolveTransactionDataConnection')->andReturn('range_a');
        $result = app(ReportService::class)->profitLossRange($this->range());
        $this->assertSame(560.0, $result['report']['net_income']);
        $this->assertSame('range_original', DB::getDefaultConnection());
    }

    public function test_range_period_helper_normalizes_values_without_reading_a_database(): void
    {
        $range = ['start_year' => '2026', 'start_month' => '11', 'end_year' => '2027', 'end_month' => '1'];
        $this->assertSame($this->period(), app(ReportService::class)->profitLossRangePeriod($range));
        $this->assertSame('testbench', DB::getDefaultConnection());
    }

    private function calls(): array
    {
        return [[app(ReportService::class), 'profitLossRange'], [app(ProfitLossSummaryService::class), 'summarizeRange'],
            [app(ConsolidatedReportService::class), 'profitLossRange'], [app(ConsolidatedReportService::class), 'profitLossSummaryRange']];
    }

    private function range(): array
    {
        return ['start_year' => 2026, 'start_month' => 11, 'end_year' => 2027, 'end_month' => 1];
    }

    private function period(): array
    {
        return ['year' => 2027, 'month' => 1] + $this->range();
    }

    private function expectedA(): array
    {
        return ['sales_revenue' => 1440.0, 'sales_returns' => 40.0, 'sales_discounts' => 20.0, 'cogs' => 580.0,
            'other_operating_income' => 0.0, 'operating_expenses' => 265.0, 'non_operating_income' => 30.0,
            'non_operating_expenses' => 5.0, 'income_tax' => 0.0, 'net_sales' => 1380.0,
            'gross_profit' => 800.0, 'operating_profit' => 535.0, 'net_profit' => 560.0];
    }

    private function account(array $report, string $code): array
    {
        $tree = array_merge($report['data']['revenue'], $report['data']['expense']);
        while ($tree !== []) {
            $node = array_shift($tree);
            foreach ($node['accounts'] as $account) {
                if ($account['code'] === $code) {
                    return $account;
                }
            }
            array_push($tree, ...$node['children']);
        }
        $this->fail('Missing account '.$code);
    }

    private function prepareDatabases(bool $shared, string $tablePrefix = 'acc_', string $connectionPrefix = ''): void
    {
        $this->shared = $shared;
        config()->set('accounting.table_prefix', $tablePrefix);
        foreach (['range_registry', 'range_master', 'range_a', 'range_b', 'range_original'] as $connection) {
            config()->set('database.connections.'.$connection, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => $connectionPrefix, 'foreign_key_constraints' => true]);
            DB::purge($connection);
        }
        Schema::connection('range_registry')->create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('slug')->unique();
            $table->string('connection_name');
        });
        foreach (['tenant-a' => ['alpha', 'range_a'], 'tenant-b' => ['beta', 'range_b'], 'original' => ['original', 'range_original']] as $id => [$slug, $connection]) {
            RangeTenant::create(['id' => $id, 'slug' => $slug, 'connection_name' => $connection]);
        }
        config()->set('tenancy.tenant_model', RangeTenant::class);
        config()->set('accounting.master_data.use_shared_database', $shared);
        config()->set('accounting.master_data.connection', 'range_master');
        foreach ($shared ? ['range_master'] : ['range_a', 'range_b'] as $master) {
            DB::setDefaultConnection($master);
            foreach (['2026_01_01_000001_create_acc_account_categories_table.php', '2026_01_01_000002_create_acc_accounts_table.php', '2026_01_01_000003_create_acc_services_table.php', '2026_06_30_000011_add_parent_id_to_acc_account_categories_table.php'] as $file) {
                (require $this->migration($file))->up();
            }
            $categories = [];
            foreach (['REVENUE' => [null, 'REVENUE'], 'SALES_REVENUE' => ['REVENUE', 'REVENUE'], 'SALES_DOMESTIC' => ['SALES_REVENUE', 'REVENUE'], 'OTHER_REVENUE' => ['REVENUE', 'REVENUE'], 'EXPENSE' => [null, 'EXPENSE'], 'COST_OF_GOODS_SOLD' => ['EXPENSE', 'EXPENSE'], 'OPERATING_EXPENSE' => ['EXPENSE', 'EXPENSE'], 'OTHER_EXPENSE' => ['EXPENSE', 'EXPENSE'], 'ASSET' => [null, 'ASSET'], 'SERVICE_REVENUE' => ['REVENUE', 'REVENUE']] as $code => [$parent, $type]) {
                if ($master === 'range_a' && $code === 'SERVICE_REVENUE') {
                    continue;
                }
                $category = AccountCategory::on($master)->create(['category_code' => $code, 'category_name' => $code, 'type' => $type,
                    'report_type' => $type === 'ASSET' ? 'BS' : 'PL', 'parent_id' => $parent ? $categories[$parent] : null]);
                $categories[$code] = $category->id;
            }
            foreach (['4001' => 'SALES_REVENUE', '4002' => 'SALES_DOMESTIC', '4020' => 'SERVICE_REVENUE', '5701' => 'SALES_REVENUE', '5601' => 'SALES_REVENUE', '4101' => 'OTHER_REVENUE', '5001' => 'COST_OF_GOODS_SOLD', '5101' => 'OPERATING_EXPENSE', '5210' => 'OPERATING_EXPENSE', '5300' => 'OTHER_EXPENSE', '1001' => 'ASSET'] as $code => $category) {
                if (! isset($categories[$category]) || ($master === 'range_b' && $code === 4002)) {
                    continue;
                }
                $account = Account::on($master)->create(['code' => (string) $code, 'name' => 'Account '.$code, 'category_id' => $categories[$category]]);
                $this->accountIds[$master][$code] = $account->id;
            }
        }
        foreach (['range_a', 'range_b'] as $connection) {
            DB::setDefaultConnection($connection);
            foreach (['2026_01_01_000005_create_acc_journal_entries_table.php', '2026_01_01_000006_create_acc_journal_entry_details_table.php', '2026_01_01_000007_create_acc_fiscal_periods_table.php', '2026_01_01_000008_create_acc_monthly_balances_table.php', '2026_01_01_000010_add_reversal_fields_to_acc_journal_entries_table.php'] as $file) {
                (require $this->migration($file))->up();
            }
            FiscalPeriod::on($connection)->create(['year' => 2026, 'month' => 11, 'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'is_closed' => true]);
            FiscalPeriod::on($connection)->create(['year' => 2026, 'month' => 12, 'start_date' => '2026-12-01', 'end_date' => '2026-12-31', 'is_closed' => false]);
            foreach ($this->accountIds[$shared ? 'range_master' : $connection] as $id) {
                MonthlyBalance::on($connection)->create(['account_id' => $id, 'fiscal_year' => 2026, 'fiscal_month' => 11, 'opening_balance' => 888888, 'ending_balance' => 9999]);
            }
            $factor = $connection === 'range_a' ? 1 : 2;
            foreach ([['4001', '2026-11-01', 0, 1000], ['5701', '2026-11-10', 50, 0], ['5601', '2026-11-12', 20, 0], ['5001', '2026-11-15', 400, 0], ['5101', '2026-11-20', 200, 0], ['5210', '2026-11-20', 15, 0], ['4101', '2026-11-30', 0, 30], ['5300', '2026-11-30', 5, 0], ['4001', '2026-12-31', 0, 300], ['5001', '2026-12-25', 100, 0], ['5101', '2026-12-20', 50, 0], ['4001', '2027-01-31', 0, 200], ['5001', '2027-01-01', 80, 0]] as [$code, $date, $debit, $credit]) {
                $this->postMovement($connection, $code, $date, $debit * $factor, $credit * $factor);
            }
            $this->postMovement($connection, '4001', '2027-01-03', 100 * $factor, 0, 'posted', 'MANUAL_JOURNAL', true);
            $this->postMovement($connection, '5701', '2027-01-04', 0, 10 * $factor, 'posted', 'MANUAL_JOURNAL', true);
            $this->postMovement($connection, $connection === 'range_a' ? '4002' : '4020', '2026-12-01', 0, $connection === 'range_a' ? 40 : 70);
            foreach (['2026-10-31', '2027-02-01'] as $date) {
                $this->postMovement($connection, '4001', $date, 0, 1000000);
            }
            $this->postMovement($connection, '4001', '2026-11-01', 0, 1000000, 'draft');
            $this->postMovement($connection, '4001', '2026-11-01', 0, 1000000, 'reversed');
            foreach (['OPENING_BALANCE', AccountOpeningBalanceService::SOURCE_TYPE] as $source) {
                $this->postMovement($connection, '4001', '2026-11-01', 0, 90000, 'posted', $source);
                $this->postMovement($connection, '4001', '2026-11-30', 100, 0, 'posted', $source, true);
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
        $master = $this->shared ? 'range_master' : $connection;
        $journal = JournalEntry::on($connection)->create(['journal_no' => 'RANGE-'.(++$this->journalNumber), 'trx_date' => $date,
            'status' => $status, 'source_type' => $source, 'is_reversal' => $reversal]);
        JournalEntryDetail::on($connection)->create(['journal_entry_id' => $journal->id, 'account_id' => $this->accountIds[$master][$code], 'debit' => $debit, 'credit' => $credit]);
        JournalEntryDetail::on($connection)->create(['journal_entry_id' => $journal->id, 'account_id' => $this->accountIds[$master]['1001'], 'debit' => $credit, 'credit' => $debit]);
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

class RangeTenant extends Model
{
    protected $connection = 'range_registry';

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
