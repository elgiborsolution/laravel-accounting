<?php

namespace ESolution\LaravelAccounting\Tests\Feature;

use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use ESolution\LaravelAccounting\Models\FiscalPeriod;
use ESolution\LaravelAccounting\Models\MonthlyBalance;
use ESolution\LaravelAccounting\Services\ClosingService;
use ESolution\LaravelAccounting\Services\ConsolidatedReportService;
use ESolution\LaravelAccounting\Services\ReportService;
use ESolution\LaravelAccounting\Support\TenantReportRunner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Fixture (natural signs; each tenant balances as A = L + E + current-year profit/loss):
 * tenant-a June A 200 (1001 100 + 1501 100), L 50, E 100, YTD P/L 50; July A 300, L 100, E 120, YTD 80.
 * tenant-b June A 360 (1001 300 + 1002 10 + 1501 50), L 110, E 150, YTD 100; July A 575, L 165, E 350, YTD 60.
 * Current: CASH under CURRENT_ASSET (1001, 1002) and 2001 under CURRENT_LIABILITY; 1501 and 2501 are non-current.
 */
class BalanceSheetSummaryServicesTest extends TestCase
{
    private const JULY = [
        'total_assets' => 875.0, 'total_liabilities' => 265.0, 'total_equity' => 470.0,
        'current_assets' => 725.0, 'current_liabilities' => 205.0, 'current_ratio' => 3.54, 'debt_ratio' => 30.29,
        'current_year_profit_loss' => 140.0, 'balance_sheet_difference' => 0.0,
    ];

    private object $tenancy;

    private bool $shared = false;

    private array $accountIds = [];

    public static function masterModes(): array
    {
        return ['tenant-local master' => [false], 'shared master' => [true]];
    }

    #[DataProvider('masterModes')]
    public function test_consolidated_summary_calculates_metrics_from_consolidated_amounts_and_restores_context(bool $shared): void
    {
        $this->prepareDatabases($shared);
        $original = BalanceSummaryTenant::findOrFail('original');
        $this->tenancy->initialize($original);
        DB::setDefaultConnection('testbench');
        $this->tenancy->initializations = [];

        $result = app(ConsolidatedReportService::class)->balanceSheetSummary('2026', '7', ['tenant-a', 'alpha', 'tenant-b']);

        $this->assertSame(['tenant_ids', 'period', 'current_year_period', 'summary'], array_keys($result));
        $this->assertSame(['tenant-a', 'tenant-b'], $result['tenant_ids']);
        $this->assertSame(['year' => 2026, 'month' => 7], $result['period']);
        $this->assertSame(['start_year' => 2026, 'start_month' => 1, 'end_year' => 2026, 'end_month' => 7], $result['current_year_period']);
        $this->assertSame(['status' => 'current'] + self::JULY + [
            'snapshots' => [['tenant_id' => 'tenant-a', 'status' => 'current'], ['tenant_id' => 'tenant-b', 'status' => 'current']],
        ], $result['summary']);
        // Ratios come from consolidated amounts, not from averaging tenant ratios (2.5 and 4.2).
        $this->assertNotSame(round((2.5 + 4.2) / 2, 2), $result['summary']['current_ratio']);

        // Totals match the existing consolidated reports.
        $service = app(ConsolidatedReportService::class);
        $sheet = $service->balanceSheet(2026, 7, ['tenant-a', 'tenant-b'])['report'];
        $this->assertSame([875.0, 265.0, 470.0], [$sheet['total_assets'], $sheet['total_liabilities'], $sheet['total_equity']]);
        $ytd = $service->profitLossRange(['start_year' => 2026, 'start_month' => 1, 'end_year' => 2026, 'end_month' => 7], ['tenant-a', 'tenant-b']);
        $this->assertSame(140.0, $ytd['report']['net_income']);
        $this->assertSame(['tenant-a', 'tenant-b', 'original', 'tenant-a', 'tenant-b', 'original', 'tenant-a', 'tenant-b', 'original'], $this->tenancy->initializations);
        $this->assertSame($original, $this->tenancy->tenant);
        $this->assertSame('testbench', DB::getDefaultConnection());
    }

    #[DataProvider('masterModes')]
    public function test_by_tenant_summary_keeps_each_tenants_amounts_and_ratios_separate(bool $shared): void
    {
        $this->prepareDatabases($shared);
        DB::setDefaultConnection('bs_original');

        $result = app(ConsolidatedReportService::class)->balanceSheetSummaryByTenant(2026, 7, ['tenant-b', 'tenant-a', 'beta']);

        $this->assertSame(['tenant_ids', 'period', 'current_year_period', 'summaries'], array_keys($result));
        $this->assertSame(['tenant-b', 'tenant-a'], $result['tenant_ids']);
        $this->assertSame(['year' => 2026, 'month' => 7], $result['period']);
        $this->assertSame([
            ['tenant_id' => 'tenant-b', 'status' => 'current', 'total_assets' => 575.0, 'total_liabilities' => 165.0, 'total_equity' => 350.0,
                'current_assets' => 525.0, 'current_liabilities' => 125.0, 'current_ratio' => 4.2, 'debt_ratio' => 28.7,
                'current_year_profit_loss' => 60.0, 'balance_sheet_difference' => 0.0],
            ['tenant_id' => 'tenant-a', 'status' => 'current', 'total_assets' => 300.0, 'total_liabilities' => 100.0, 'total_equity' => 120.0,
                'current_assets' => 200.0, 'current_liabilities' => 80.0, 'current_ratio' => 2.5, 'debt_ratio' => 33.33,
                'current_year_profit_loss' => 80.0, 'balance_sheet_difference' => 0.0],
        ], $result['summaries']);
        $perTenant = app(ReportService::class)->balanceSheet(2026, 7, ['tenant-b', 'tenant-a']);
        $this->assertSame(575.0, $perTenant[0]['report']['total_assets']);
        $this->assertSame('bs_original', DB::getDefaultConnection());
        $this->assertFalse($this->tenancy->initialized);
    }

    #[DataProvider('masterModes')]
    public function test_by_month_returns_month_end_positions_in_order_without_summing_months(bool $shared): void
    {
        $this->prepareDatabases($shared);
        $range = ['start_year' => '2026', 'start_month' => '5', 'end_year' => 2026, 'end_month' => 8];

        $result = app(ConsolidatedReportService::class)->balanceSheetSummaryByMonth($range, ['tenant-a', 'tenant-b']);

        $this->assertSame(['tenant_ids', 'period', 'summaries'], array_keys($result));
        $this->assertSame(['year' => 2026, 'month' => 8, 'start_year' => 2026, 'start_month' => 5, 'end_year' => 2026, 'end_month' => 8], $result['period']);
        $this->assertSame([['year' => 2026, 'month' => 5], ['year' => 2026, 'month' => 6], ['year' => 2026, 'month' => 7], ['year' => 2026, 'month' => 8]],
            array_column($result['summaries'], 'period'));
        $this->assertSame(['start_year' => 2026, 'start_month' => 1, 'end_year' => 2026, 'end_month' => 6], $result['summaries'][1]['current_year_period']);
        [$may, $june, $july, $august] = array_column($result['summaries'], 'summary');
        $this->assertSame(['missing', null, null, null, 0.0, null],
            [$may['status'], $may['total_assets'], $may['current_ratio'], $may['debt_ratio'], $may['current_year_profit_loss'], $may['balance_sheet_difference']]);
        $this->assertSame([
            'status' => 'current', 'total_assets' => 560.0, 'total_liabilities' => 160.0, 'total_equity' => 250.0,
            'current_assets' => 410.0, 'current_liabilities' => 140.0, 'current_ratio' => 2.93, 'debt_ratio' => 28.57,
            'current_year_profit_loss' => 150.0, 'balance_sheet_difference' => 0.0,
        ], array_diff_key($june, ['snapshots' => true]));
        $this->assertSame(self::JULY, array_diff_key($july, ['status' => true, 'snapshots' => true]));
        // Open month: explicit status, no zero amounts or ratios; the posted-journal profit/loss is still reported.
        $this->assertSame('missing', $august['status']);
        foreach (['total_assets', 'current_assets', 'current_liabilities', 'current_ratio', 'debt_ratio', 'balance_sheet_difference'] as $key) {
            $this->assertNull($august[$key]);
        }
        $this->assertSame(140.0, $august['current_year_profit_loss']);
        $this->assertSame([['tenant_id' => 'tenant-a', 'status' => 'missing'], ['tenant_id' => 'tenant-b', 'status' => 'missing']], $august['snapshots']);
        $this->assertSame(['tenant-a', 'tenant-b'], $this->tenancy->initializations); // One pass per tenant.
        $this->assertSame('testbench', DB::getDefaultConnection());

        $single = app(ConsolidatedReportService::class)->balanceSheetSummaryByMonth(
            ['start_year' => 2026, 'start_month' => 7, 'end_year' => 2026, 'end_month' => 7], ['tenant-a', 'tenant-b']);
        $this->assertSame($july, $single['summaries'][0]['summary']);
    }

    public function test_by_month_crosses_years_chronologically_and_restarts_the_fiscal_year(): void
    {
        $this->prepareDatabases(false);

        $result = app(ConsolidatedReportService::class)->balanceSheetSummaryByMonth(
            ['start_year' => 2025, 'start_month' => 11, 'end_year' => 2026, 'end_month' => 2], ['tenant-a']);

        $this->assertSame([['year' => 2025, 'month' => 11], ['year' => 2025, 'month' => 12], ['year' => 2026, 'month' => 1], ['year' => 2026, 'month' => 2]],
            array_column($result['summaries'], 'period'));
        $this->assertSame([2025, 2025, 2026, 2026], array_map(fn ($entry) => $entry['current_year_period']['start_year'], $result['summaries']));
    }

    public function test_fiscal_start_month_bounds_current_year_profit_loss(): void
    {
        $this->prepareDatabases(false);
        config()->set('accounting.fiscal.start_month', '7');
        $service = app(ConsolidatedReportService::class);

        $july = $service->balanceSheetSummary(2026, 7, ['tenant-a', 'tenant-b']);
        $this->assertSame(['start_year' => 2026, 'start_month' => 7, 'end_year' => 2026, 'end_month' => 7], $july['current_year_period']);
        // Only July journals: tenant-a +30, tenant-b -40. June profit (150) is not in equity, so it
        // remains in the difference instead of being counted as current-year profit.
        $this->assertSame(-10.0, $july['summary']['current_year_profit_loss']);
        $this->assertSame(150.0, $july['summary']['balance_sheet_difference']);

        $june = $service->balanceSheetSummaryByTenant(2026, 6, ['tenant-a', 'tenant-b']);
        $this->assertSame(['start_year' => 2025, 'start_month' => 7, 'end_year' => 2026, 'end_month' => 6], $june['current_year_period']);
        $this->assertSame([50.0, 100.0], array_column($june['summaries'], 'current_year_profit_loss'));
        $this->assertSame([0.0, 0.0], array_column($june['summaries'], 'balance_sheet_difference'));
    }

    public function test_current_classification_is_configurable_by_category_and_account_code(): void
    {
        $this->prepareDatabases(false);
        $service = app(ConsolidatedReportService::class);

        // Partial overrides merge over the default category mappings.
        config()->set('accounting.balance_sheet_summary', ['account_mappings' => ['1002' => 'non_current']]);
        $summary = $service->balanceSheetSummary(2026, 7, ['tenant-a', 'tenant-b'])['summary'];
        $this->assertSame([700.0, 205.0, 3.41], [$summary['current_assets'], $summary['current_liabilities'], $summary['current_ratio']]);

        // Account overrides beat inherited categories; zero current liabilities give a null ratio.
        config()->set('accounting.balance_sheet_summary', [
            'account_mappings' => ['1501' => 'current', '1002' => 'non_current'],
            'category_mappings' => ['CURRENT_LIABILITY' => 'non_current'],
        ]);
        $summary = $service->balanceSheetSummary(2026, 7, ['tenant-a', 'tenant-b'])['summary'];
        $this->assertSame([850.0, 0.0, null, 30.29], [$summary['current_assets'], $summary['current_liabilities'], $summary['current_ratio'], $summary['debt_ratio']]);
    }

    public function test_zero_denominators_return_null_ratios(): void
    {
        $this->prepareDatabases(false);
        MonthlyBalance::on('bs_a')->where('fiscal_month', 7)->update(['ending_balance' => 0]);

        $rows = app(ConsolidatedReportService::class)->balanceSheetSummaryByTenant(2026, 7, ['tenant-a'])['summaries'];

        $this->assertSame([
            'tenant_id' => 'tenant-a', 'status' => 'current', 'total_assets' => 0.0, 'total_liabilities' => 0.0, 'total_equity' => 0.0,
            'current_assets' => 0.0, 'current_liabilities' => 0.0, 'current_ratio' => null, 'debt_ratio' => null,
            'current_year_profit_loss' => 80.0, 'balance_sheet_difference' => -80.0,
        ], $rows[0]);
    }

    public static function staleScenarios(): array
    {
        return [
            'reopened month keeps old snapshots' => ['reopened', 'stale'],
            'earlier month reclosed after it' => ['out_of_order', 'stale'],
            'earlier month with journals never closed' => ['gap', 'stale'],
            'snapshot written with the legacy credit-normal asset sign' => ['legacy_sign', 'stale'],
            'one tenant missing, another stale' => ['missing_and_stale', 'missing'],
        ];
    }

    #[DataProvider('staleScenarios')]
    public function test_missing_and_stale_snapshots_are_reported_without_snapshot_metrics(string $scenario, string $consolidated): void
    {
        $this->prepareDatabases(false);
        match ($scenario) {
            'reopened' => FiscalPeriod::on('bs_b')->where('month', 7)->update(['is_closed' => false, 'closed_at' => null]),
            'out_of_order' => FiscalPeriod::on('bs_b')->where('month', 6)->update(['closed_at' => '2026-08-15 00:00:00']),
            'gap' => $this->journal('bs_b', '2026-05-20'),
            // closeMonth() before the case-insensitive type fix: ending = opening + credit - debit.
            'legacy_sign' => MonthlyBalance::on('bs_b')->where('fiscal_month', 7)->where('account_id', $this->accountIds['bs_b']['1001'])
                ->update(['opening_balance' => -300, 'total_debit' => 200, 'total_credit' => 0, 'ending_balance' => -500]),
            'missing_and_stale' => [
                FiscalPeriod::on('bs_b')->where('month', 7)->update(['is_closed' => false]),
                MonthlyBalance::on('bs_a')->where('fiscal_month', 7)->delete(),
                FiscalPeriod::on('bs_a')->where('month', 7)->update(['is_closed' => false]),
            ],
        };
        $service = app(ConsolidatedReportService::class);
        $tenantStatus = $scenario === 'missing_and_stale' ? ['missing', 'stale'] : ['current', 'stale'];
        $snapshotKeys = ['total_assets', 'total_liabilities', 'total_equity', 'current_assets', 'current_liabilities',
            'current_ratio', 'debt_ratio', 'balance_sheet_difference'];

        $summary = $service->balanceSheetSummary(2026, 7, ['tenant-a', 'tenant-b'])['summary'];
        $this->assertSame($consolidated, $summary['status']);
        $this->assertSame(array_fill_keys($snapshotKeys, null), array_intersect_key($summary, array_flip($snapshotKeys)));
        $this->assertSame(140.0, $summary['current_year_profit_loss']);
        $this->assertSame($tenantStatus, array_column($summary['snapshots'], 'status'));

        $rows = $service->balanceSheetSummaryByTenant(2026, 7, ['tenant-a', 'tenant-b'])['summaries'];
        $this->assertSame($tenantStatus, array_column($rows, 'status'));
        $this->assertNull($rows[1]['total_assets']);
        $this->assertNull($rows[1]['debt_ratio']);
        if ($tenantStatus[0] === 'current') {
            $this->assertSame([300.0, 2.5], [$rows[0]['total_assets'], $rows[0]['current_ratio']]); // A healthy tenant still reports.
        }

        $june = $service->balanceSheetSummaryByMonth(['start_year' => 2026, 'start_month' => 6, 'end_year' => 2026, 'end_month' => 6], ['tenant-a', 'tenant-b']);
        $this->assertSame($scenario === 'gap' ? 'stale' : 'current', $june['summaries'][0]['summary']['status']);
    }

    #[DataProvider('masterModes')]
    public function test_real_closing_stores_natural_signs_and_counts_profit_once(bool $shared): void
    {
        $this->prepareDatabases($shared);
        $this->journal('bs_a', '2026-08-05', ['1001' => 70, '4001' => -70]);
        // A host entry moving profit into equity also posts to revenue, so it is not counted twice.
        $this->journal('bs_a', '2026-08-25', ['4001' => 80, '3001' => -80]);
        $this->travelTo(Carbon::parse('2026-09-02 10:00:00'));
        $runner = app(TenantReportRunner::class);
        $runner->run(['tenant-a', 'tenant-b'], fn () => app(ClosingService::class)->closeMonth(2026, 8));
        $service = app(ConsolidatedReportService::class);

        // Debit-normal asset: opening 200 + debit 70 (the legacy sign stored 200 - 70).
        $cash = MonthlyBalance::on('bs_a')->where('fiscal_month', 8)->where('account_id', $this->accountIds[$shared ? 'bs_master' : 'bs_a']['1001'])->first();
        $this->assertSame([200.0, 270.0], [(float) $cash->opening_balance, (float) $cash->ending_balance]);

        $rows = $service->balanceSheetSummaryByTenant(2026, 8, ['tenant-a', 'tenant-b'])['summaries'];
        $this->assertSame(['current', 370.0, 100.0, 200.0, 270.0, 70.0, 0.0],
            [$rows[0]['status'], $rows[0]['total_assets'], $rows[0]['total_liabilities'], $rows[0]['total_equity'],
                $rows[0]['current_assets'], $rows[0]['current_year_profit_loss'], $rows[0]['balance_sheet_difference']]);
        $august = $service->balanceSheetSummary(2026, 8, ['tenant-a', 'tenant-b'])['summary'];
        $this->assertSame([
            'status' => 'current', 'total_assets' => 945.0, 'total_liabilities' => 265.0, 'total_equity' => 550.0,
            'current_assets' => 795.0, 'current_liabilities' => 205.0, 'current_ratio' => 3.88, 'debt_ratio' => 28.04,
            'current_year_profit_loss' => 130.0, 'balance_sheet_difference' => 0.0,
        ], array_diff_key($august, ['snapshots' => true]));

        $runner->run(['tenant-b'], fn () => app(ClosingService::class)->reopenMonth(2026, 8));
        $rows = $service->balanceSheetSummaryByTenant(2026, 8, ['tenant-a', 'tenant-b'])['summaries'];
        $this->assertSame(['current', 'stale'], array_column($rows, 'status'));
        $this->assertSame('stale', $service->balanceSheetSummary(2026, 8, ['tenant-a', 'tenant-b'])['summary']['status']);
        $this->assertSame('current', $service->balanceSheetSummary(2026, 7, ['tenant-a', 'tenant-b'])['summary']['status']);
        $this->assertSame('testbench', DB::getDefaultConnection());
    }

    public static function invalidInputs(): array
    {
        $range = ['start_year' => 2026, 'start_month' => 6, 'end_year' => 2026, 'end_month' => 7];

        return [
            'empty tenants' => [2026, 7, $range, [], []],
            'unknown tenant' => [2026, 7, $range, ['tenant-a', 'missing'], []],
            'non-list tenants' => [2026, 7, $range, ['first' => 'tenant-a'], []],
            'month 13' => [2026, 13, ['end_month' => 13] + $range, ['tenant-a'], []],
            'year 0' => [0, 7, ['start_year' => 0] + $range, ['tenant-a'], []],
            'text month' => [2026, 'July', ['start_month' => 'June'] + $range, ['tenant-a'], []],
            'reversed range' => [null, 7, ['start_month' => 8] + $range, ['tenant-a'], []],
            'incomplete range' => [null, 7, ['start_year' => 2026, 'start_month' => 6], ['tenant-a'], []],
            'fiscal start month 13' => [2026, 7, $range, ['tenant-a'], ['accounting.fiscal.start_month' => 13]],
            'fiscal start month text' => [2026, 7, $range, ['tenant-a'], ['accounting.fiscal.start_month' => 'July']],
            'mappings not an array' => [2026, 7, $range, ['tenant-a'], ['accounting.balance_sheet_summary' => 'current']],
            'unknown classification' => [2026, 7, $range, ['tenant-a'], ['accounting.balance_sheet_summary.category_mappings' => ['CASH' => 'short_term']]],
            'account mappings not an array' => [2026, 7, $range, ['tenant-a'], ['accounting.balance_sheet_summary.account_mappings' => 'current']],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_periods_tenants_and_configuration_are_validated_before_any_tenant_runs(
        mixed $year, mixed $month, array $range, array $ids, array $config
    ): void {
        $this->prepareDatabases(false);
        config()->set($config);
        $original = BalanceSummaryTenant::findOrFail('original');
        $this->tenancy->initialize($original);
        $this->tenancy->initializations = [];
        $service = app(ConsolidatedReportService::class);
        $calls = [fn () => $service->balanceSheetSummaryByMonth($range, $ids)];
        if ($year !== null) {
            $calls[] = fn () => $service->balanceSheetSummary($year, $month, $ids);
            $calls[] = fn () => $service->balanceSheetSummaryByTenant($year, $month, $ids);
        }

        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('Invalid input must fail before tenant execution.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertSame([], $this->tenancy->initializations);
        $this->assertSame($original, $this->tenancy->tenant);
        $this->assertSame('bs_original', DB::getDefaultConnection());
    }

    #[DataProvider('masterModes')]
    public function test_query_failures_restore_the_active_tenant_and_connection_override(bool $shared): void
    {
        $this->prepareDatabases($shared);
        Schema::connection('bs_b')->drop('acc_fiscal_periods');
        $original = BalanceSummaryTenant::findOrFail('original');
        $this->tenancy->initialize($original);
        DB::setDefaultConnection('testbench');
        $service = app(ConsolidatedReportService::class);

        foreach ([
            fn () => $service->balanceSheetSummary(2026, 7, ['tenant-a', 'tenant-b']),
            fn () => $service->balanceSheetSummaryByTenant(2026, 7, ['tenant-a', 'tenant-b']),
            fn () => $service->balanceSheetSummaryByMonth(['start_year' => 2026, 'start_month' => 6, 'end_year' => 2026, 'end_month' => 7], ['tenant-a', 'tenant-b']),
        ] as $call) {
            try {
                $call();
                $this->fail('A failed tenant must not be skipped.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('acc_fiscal_periods', $e->getMessage());
            }
            $this->assertSame($original, $this->tenancy->tenant);
            $this->assertSame('testbench', DB::getDefaultConnection());
        }
    }

    public function test_incompatible_definitions_still_fail_consolidation(): void
    {
        $this->prepareDatabases(false);
        Account::on('bs_b')->where('code', '1001')->update(['name' => 'Different cash meaning']);

        try {
            app(ConsolidatedReportService::class)->balanceSheetSummary(2026, 7, ['tenant-a', 'tenant-b']);
            $this->fail('Incompatible definitions must not be consolidated.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('consolidation', $e->errors());
        }
        // Per-tenant summaries do not merge definitions.
        $this->assertSame('current', app(ConsolidatedReportService::class)->balanceSheetSummaryByTenant(2026, 7, ['tenant-a', 'tenant-b'])['summaries'][1]['status']);
    }

    #[DataProvider('masterModes')]
    public function test_profit_detail_reports_prior_and_current_months_for_each_scope(bool $shared): void
    {
        $this->prepareDatabases($shared);
        $original = BalanceSummaryTenant::findOrFail('original');
        $this->tenancy->initialize($original);
        DB::setDefaultConnection('testbench');
        $this->tenancy->initializations = [];
        $service = app(ConsolidatedReportService::class);

        // tenant-a: June +50, July +30; tenant-b: June +100, July -40.
        $july = $service->balanceSheetProfitDetail('2026', '7', ['tenant-a', 'alpha', 'tenant-b']);
        $this->assertSame([
            'tenant_ids' => ['tenant-a', 'tenant-b'],
            'period' => ['year' => 2026, 'month' => 7],
            'summary' => ['prior_months_profit_loss' => 150.0, 'current_month_profit_loss' => -10.0],
        ], $july);

        $byTenant = $service->balanceSheetProfitDetailByTenant(2026, 7, ['tenant-b', 'tenant-a']);
        $this->assertSame(['tenant_ids', 'period', 'summaries'], array_keys($byTenant));
        $this->assertSame([
            ['tenant_id' => 'tenant-b', 'prior_months_profit_loss' => 100.0, 'current_month_profit_loss' => -40.0],
            ['tenant_id' => 'tenant-a', 'prior_months_profit_loss' => 50.0, 'current_month_profit_loss' => 30.0],
        ], $byTenant['summaries']);

        $this->tenancy->initializations = [];
        $byMonth = $service->balanceSheetProfitDetailByMonth(['start_year' => 2026, 'start_month' => '6', 'end_year' => 2026, 'end_month' => 8], ['tenant-a', 'tenant-b']);
        $this->assertSame(['year' => 2026, 'month' => 8, 'start_year' => 2026, 'start_month' => 6, 'end_year' => 2026, 'end_month' => 8], $byMonth['period']);
        $this->assertSame([
            ['period' => ['year' => 2026, 'month' => 6], 'summary' => ['prior_months_profit_loss' => 0.0, 'current_month_profit_loss' => 150.0]],
            ['period' => ['year' => 2026, 'month' => 7], 'summary' => ['prior_months_profit_loss' => 150.0, 'current_month_profit_loss' => -10.0]],
            ['period' => ['year' => 2026, 'month' => 8], 'summary' => ['prior_months_profit_loss' => 140.0, 'current_month_profit_loss' => 0.0]],
        ], $byMonth['summaries']);
        $this->assertSame(['tenant-a', 'tenant-b', 'original'], $this->tenancy->initializations); // One pass per tenant.

        $this->assertSame([['tenant_id' => 'tenant-b', 'report' => ['prior_months_profit_loss' => 100.0, 'current_month_profit_loss' => -40.0]]],
            app(ReportService::class)->profitLossDetail(2026, 7, ['tenant-b']));
        // Informational only: equity totals are unchanged.
        $this->assertSame(470.0, $service->balanceSheetSummary(2026, 7, ['tenant-a', 'tenant-b'])['summary']['total_equity']);
        $this->assertSame($original, $this->tenancy->tenant);
        $this->assertSame('testbench', DB::getDefaultConnection());
    }

    #[DataProvider('masterModes')]
    public function test_profit_detail_excludes_closing_transfers_and_opening_journals(bool $shared): void
    {
        $this->prepareDatabases($shared);
        $monthEnd = $this->closingService($shared ? 'bs_master' : 'bs_a', 'MONTH_END_CLOSING');
        $yearEnd = $this->closingService($shared ? 'bs_master' : 'bs_a', 'YEAR_END_CLOSING');
        // tenant-a: service-marked transfers (revenue/expense -> equity), plus a reversal-like entry
        // that copies the service marker.
        $this->journal('bs_a', '2026-06-30', ['4001' => 50, '3001' => -50], ['service_id' => $yearEnd]);
        $this->journal('bs_a', '2026-07-31', ['4001' => 60, '3001' => -30, '5001' => -30], ['service_id' => $monthEnd]);
        $this->journal('bs_a', '2026-07-31', ['4001' => -10, '3001' => 10], ['service_id' => $monthEnd]);
        // tenant-b: a manual closing entry marked by source_type, and both opening-journal markers.
        $this->journal('bs_b', '2026-07-31', ['3001' => 40, '5001' => -40], ['source_type' => 'MONTH_END_CLOSING']);
        $this->journal('bs_b', '2026-06-01', ['1001' => 999, '4001' => -999], ['source_type' => 'OPENING_BALANCE']);
        $this->journal('bs_b', '2026-07-01', ['5001' => 5, '3001' => -5], ['source_type' => 'ACCOUNT_OPENING_BALANCE']);
        $service = app(ConsolidatedReportService::class);

        // Closing transfers do not erase the reported profit.
        $this->assertSame(['prior_months_profit_loss' => 150.0, 'current_month_profit_loss' => -10.0],
            $service->balanceSheetProfitDetail(2026, 7, ['tenant-a', 'tenant-b'])['summary']);
        $this->assertSame([[50.0, 30.0], [100.0, -40.0]], array_map(
            fn ($row) => [$row['prior_months_profit_loss'], $row['current_month_profit_loss']],
            $service->balanceSheetProfitDetailByTenant(2026, 7, ['tenant-a', 'tenant-b'])['summaries']
        ));
        // Contrast: the journal-based current-year metric nets closing transfers (opening journals excluded).
        $this->assertSame(110.0, $service->balanceSheetSummary(2026, 7, ['tenant-a', 'tenant-b'])['summary']['current_year_profit_loss']);

        // Empty marker lists disable closing exclusion; opening journals stay excluded.
        config()->set('accounting.profit_loss_detail', ['closing_service_codes' => [], 'closing_source_types' => []]);
        $this->assertSame(['prior_months_profit_loss' => 100.0, 'current_month_profit_loss' => 10.0],
            $service->balanceSheetProfitDetail(2026, 7, ['tenant-a', 'tenant-b'])['summary']);
        // A missing key keeps the defaults: service markers still excluded, tenant-b's manual
        // source_type closing (+40 back to profit) now included.
        config()->set('accounting.profit_loss_detail', ['closing_source_types' => []]);
        $this->assertSame(['prior_months_profit_loss' => 150.0, 'current_month_profit_loss' => 30.0],
            $service->balanceSheetProfitDetail(2026, 7, ['tenant-a', 'tenant-b'])['summary']);
    }

    public function test_profit_detail_accumulates_across_fiscal_year_rollover(): void
    {
        $this->prepareDatabases(false);
        $this->journal('bs_a', '2025-11-15', ['1001' => 200, '4001' => -200]);
        $this->journal('bs_a', '2025-12-20', ['5001' => 20, '1001' => -20]);
        $this->journal('bs_a', '2026-01-10', ['1001' => 15, '4001' => -15]);
        $service = app(ConsolidatedReportService::class);

        $result = $service->balanceSheetProfitDetailByMonth(['start_year' => 2025, 'start_month' => 12, 'end_year' => 2026, 'end_month' => 1], ['tenant-a']);
        $this->assertSame([
            ['period' => ['year' => 2025, 'month' => 12], 'summary' => ['prior_months_profit_loss' => 200.0, 'current_month_profit_loss' => -20.0]],
            ['period' => ['year' => 2026, 'month' => 1], 'summary' => ['prior_months_profit_loss' => 180.0, 'current_month_profit_loss' => 15.0]],
        ], $result['summaries']);
        // Prior fiscal years stay included regardless of the fiscal-year start.
        config()->set('accounting.fiscal.start_month', 4);
        $this->assertSame(['prior_months_profit_loss' => 195.0, 'current_month_profit_loss' => 50.0],
            $service->balanceSheetProfitDetail(2026, 6, ['tenant-a'])['summary']);
    }

    public static function invalidProfitDetailInputs(): array
    {
        $range = ['start_year' => 2026, 'start_month' => 6, 'end_year' => 2026, 'end_month' => 7];

        return [
            'empty tenants' => [2026, 7, $range, [], []],
            'unknown tenant' => [2026, 7, $range, ['tenant-a', 'missing'], []],
            'month 13' => [2026, 13, ['end_month' => 13] + $range, ['tenant-a'], []],
            'reversed range' => [null, 7, ['start_month' => 8] + $range, ['tenant-a'], []],
            'markers not an array' => [2026, 7, $range, ['tenant-a'], ['accounting.profit_loss_detail' => 'MONTH_END_CLOSING']],
            'blank service code' => [2026, 7, $range, ['tenant-a'], ['accounting.profit_loss_detail.closing_service_codes' => ['']]],
            'non-string source type' => [2026, 7, $range, ['tenant-a'], ['accounting.profit_loss_detail.closing_source_types' => [1]]],
        ];
    }

    #[DataProvider('invalidProfitDetailInputs')]
    public function test_profit_detail_validates_before_any_tenant_runs(mixed $year, mixed $month, array $range, array $ids, array $config): void
    {
        $this->prepareDatabases(false);
        config()->set($config);
        $original = BalanceSummaryTenant::findOrFail('original');
        $this->tenancy->initialize($original);
        $this->tenancy->initializations = [];
        $service = app(ConsolidatedReportService::class);
        $calls = [fn () => $service->balanceSheetProfitDetailByMonth($range, $ids)];
        if ($year !== null) {
            $calls[] = fn () => $service->balanceSheetProfitDetail($year, $month, $ids);
            $calls[] = fn () => $service->balanceSheetProfitDetailByTenant($year, $month, $ids);
            $calls[] = fn () => app(ReportService::class)->profitLossDetail($year, $month, $ids);
        }

        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('Invalid input must fail before tenant execution.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertSame([], $this->tenancy->initializations);
        $this->assertSame($original, $this->tenancy->tenant);
        $this->assertSame('bs_original', DB::getDefaultConnection());
    }

    #[DataProvider('masterModes')]
    public function test_profit_detail_failures_restore_the_active_tenant_and_connection(bool $shared): void
    {
        $this->prepareDatabases($shared);
        Schema::connection('bs_b')->drop('acc_journal_entry_details');
        $original = BalanceSummaryTenant::findOrFail('original');
        $this->tenancy->initialize($original);
        DB::setDefaultConnection('testbench');
        $service = app(ConsolidatedReportService::class);

        foreach ([
            fn () => $service->balanceSheetProfitDetail(2026, 7, ['tenant-a', 'tenant-b']),
            fn () => $service->balanceSheetProfitDetailByTenant(2026, 7, ['tenant-a', 'tenant-b']),
            fn () => $service->balanceSheetProfitDetailByMonth(['start_year' => 2026, 'start_month' => 6, 'end_year' => 2026, 'end_month' => 7], ['tenant-a', 'tenant-b']),
        ] as $call) {
            try {
                $call();
                $this->fail('A failed tenant must not be skipped.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('acc_journal_entry_details', $e->getMessage());
            }
            $this->assertSame($original, $this->tenancy->tenant);
            $this->assertSame('testbench', DB::getDefaultConnection());
        }
    }

    private function closingService(string $connection, string $code): string
    {
        $id = (string) Str::uuid();
        DB::connection($connection)->table('acc_services')->insert([
            'id' => $id, 'service_code' => $code, 'service_name' => $code, 'module_name' => 'CLOSING', 'is_active' => true,
        ]);

        return $id;
    }

    /**
     * Posted journal; $lines maps account code => signed amount (positive debit, negative credit).
     * $attributes may set journal markers such as service_id or source_type.
     */
    private function journal(string $connection, string $date, array $lines = [], array $attributes = []): void
    {
        $id = (string) Str::uuid();
        DB::connection($connection)->table('acc_journal_entries')->insert($attributes + [
            'id' => $id, 'journal_no' => 'JV-'.Str::random(8), 'trx_date' => $date, 'status' => 'posted',
        ]);
        foreach ($lines as $code => $amount) {
            DB::connection($connection)->table('acc_journal_entry_details')->insert([
                'id' => (string) Str::uuid(), 'journal_entry_id' => $id,
                'account_id' => $this->accountIds[$this->shared ? 'bs_master' : $connection][(string) $code],
                'debit' => max($amount, 0), 'credit' => max(-$amount, 0),
            ]);
        }
    }

    private function prepareDatabases(bool $shared): void
    {
        $this->shared = $shared;
        $this->travelTo(Carbon::parse('2026-08-20 12:00:00'));
        foreach (['bs_registry', 'bs_master', 'bs_a', 'bs_b', 'bs_original'] as $connection) {
            config()->set('database.connections.'.$connection, [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
            ]);
            DB::purge($connection);
        }
        Schema::connection('bs_registry')->create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('slug')->unique();
            $table->string('connection_name');
        });
        foreach (['tenant-a' => ['alpha', 'bs_a'], 'tenant-b' => ['beta', 'bs_b'], 'original' => ['original', 'bs_original']] as $id => [$slug, $connection]) {
            BalanceSummaryTenant::create(['id' => $id, 'slug' => $slug, 'connection_name' => $connection]);
        }
        config()->set('tenancy.tenant_model', BalanceSummaryTenant::class);
        config()->set('accounting.master_data.use_shared_database', $shared);
        config()->set('accounting.master_data.connection', 'bs_master');
        config()->set('accounting.fiscal.start_month', 1);

        foreach ($shared ? ['bs_master'] : ['bs_a', 'bs_b'] as $connection) {
            $this->createMasterData($connection);
        }
        $balances = [
            'bs_a' => [
                6 => ['1001' => 100, '1501' => 100, '2001' => 30, '2501' => 20, '3001' => 100],
                7 => ['1001' => 200, '1501' => 100, '2001' => 80, '2501' => 20, '3001' => 120],
            ],
            'bs_b' => [
                6 => ['1001' => 300, '1002' => 10, '1501' => 50, '2001' => 110, '3001' => 150],
                7 => ['1001' => 500, '1002' => 25, '1501' => 50, '2001' => 125, '2501' => 40, '3001' => 350],
            ],
        ];
        $journals = [
            'bs_a' => ['2026-06-10' => ['1001' => 50, '4001' => -50], '2026-07-15' => ['1001' => 30, '5001' => 30, '4001' => -60]],
            'bs_b' => ['2026-06-10' => ['1001' => 100, '4001' => -100], '2026-07-20' => ['5001' => 40, '1001' => -40]],
        ];
        foreach ($balances as $connection => $months) {
            DB::setDefaultConnection($connection);
            foreach (['2026_01_01_000005_create_acc_journal_entries_table.php', '2026_01_01_000006_create_acc_journal_entry_details_table.php',
                '2026_01_01_000007_create_acc_fiscal_periods_table.php', '2026_01_01_000008_create_acc_monthly_balances_table.php'] as $migration) {
                (require $this->migration($migration))->up();
            }
            $master = $shared ? 'bs_master' : $connection;
            foreach ($journals[$connection] as $date => $lines) {
                $this->journal($connection, $date, $lines);
            }
            foreach ($months as $month => $amounts) {
                $start = Carbon::create(2026, $month, 1);
                FiscalPeriod::on($connection)->create([
                    'year' => 2026, 'month' => $month, 'start_date' => $start->toDateString(), 'end_date' => $start->copy()->endOfMonth()->toDateString(),
                    'is_closed' => true, 'closed_at' => $start->copy()->addMonth()->toDateTimeString(),
                ]);
                foreach ($amounts as $code => $amount) {
                    MonthlyBalance::on($connection)->create([
                        'fiscal_year' => 2026, 'fiscal_month' => $month,
                        'account_id' => $this->accountIds[$master][$code], 'ending_balance' => $amount,
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
    }

    private function createMasterData(string $connection): void
    {
        DB::setDefaultConnection($connection);
        foreach (['2026_01_01_000001_create_acc_account_categories_table.php', '2026_01_01_000002_create_acc_accounts_table.php',
            '2026_01_01_000003_create_acc_services_table.php', '2026_06_30_000011_add_parent_id_to_acc_account_categories_table.php'] as $migration) {
            (require $this->migration($migration))->up();
        }
        $categories = [];
        foreach ([
            'ASSET' => [null, 'ASSET'], 'CURRENT_ASSET' => ['ASSET', 'ASSET'], 'CASH' => ['CURRENT_ASSET', 'ASSET'], 'FIXED_ASSET' => ['ASSET', 'ASSET'],
            'LIABILITY' => [null, 'LIABILITY'], 'CURRENT_LIABILITY' => ['LIABILITY', 'LIABILITY'], 'LONG_TERM_LIABILITY' => ['LIABILITY', 'LIABILITY'],
            'EQUITY' => [null, 'EQUITY'], 'REVENUE' => [null, 'REVENUE'], 'EXPENSE' => [null, 'EXPENSE'],
        ] as $code => [$parent, $type]) {
            $categories[$code] = AccountCategory::on($connection)->create([
                'parent_id' => $parent === null ? null : $categories[$parent], 'type' => $type,
                'category_code' => $code, 'category_name' => $code, 'report_type' => in_array($type, ['REVENUE', 'EXPENSE'], true) ? 'PL' : 'BS',
            ])->id;
        }
        foreach (['1001' => 'CASH', '1002' => 'CASH', '1501' => 'FIXED_ASSET', '2001' => 'CURRENT_LIABILITY', '2501' => 'LONG_TERM_LIABILITY',
            '3001' => 'EQUITY', '4001' => 'REVENUE', '5001' => 'EXPENSE'] as $code => $category) {
            $this->accountIds[$connection][$code] = Account::on($connection)->create([
                'category_id' => $categories[$category], 'code' => $code, 'name' => 'Account '.$code,
            ])->id;
        }
    }

    private function migration(string $filename): string
    {
        return dirname(__DIR__, 2).'/database/migrations/accounting/'.$filename;
    }
}

class BalanceSummaryTenant extends Model
{
    protected $connection = 'bs_registry';

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
