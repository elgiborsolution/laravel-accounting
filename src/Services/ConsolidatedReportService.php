<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Enums\JournalStatus;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\FiscalPeriod;
use ESolution\LaravelAccounting\Models\JournalEntry;
use ESolution\LaravelAccounting\Models\MonthlyBalance;
use ESolution\LaravelAccounting\Repositories\AccountCategoryRepository;
use ESolution\LaravelAccounting\Repositories\AccountRepository;
use ESolution\LaravelAccounting\Support\CentralReportContext;
use ESolution\LaravelAccounting\Support\ProfitLossSummaryCalculator;
use ESolution\LaravelAccounting\Support\TenantReportRunner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ConsolidatedReportService
{
    /** Default current/non-current classification by stable code; see accounting.balance_sheet_summary. */
    protected const DEFAULT_BALANCE_SHEET_MAPPINGS = [
        'account_mappings' => [],
        'category_mappings' => ['CURRENT_ASSET' => 'current', 'CURRENT_LIABILITY' => 'current'],
    ];

    /** Amount fields of categoryBalanceTree(), in AccountBalanceService::getBalances() terms. */
    protected const BALANCE_FIELDS = ['opening_balance', 'total_debit', 'total_credit', 'ending_balance'];

    public function __construct(
        protected ReportService $reports,
        protected TenantReportRunner $tenants,
        protected AccountCategoryRepository $categories,
        protected AccountRepository $accounts
    ) {}

    public function profitLoss($year, $month, array $tenantIds): array
    {
        return $this->consolidate('profitLoss', ['revenue' => 'REVENUE', 'expense' => 'EXPENSE'], $year, $month, $tenantIds);
    }

    public function balanceSheet($year, $month, array $tenantIds): array
    {
        return $this->consolidate('balanceSheet', ['asset' => 'ASSET', 'liability' => 'LIABILITY', 'equity' => 'EQUITY'], $year, $month, $tenantIds);
    }

    public function profitLossSummary($year, $month, array $tenantIds): array
    {
        $calculator = app(ProfitLossSummaryCalculator::class);
        $period = $calculator->period($year, $month);
        $mappings = $calculator->mappings();
        $report = $this->profitLoss($period['year'], $period['month'], $tenantIds);

        return [
            'tenant_ids' => $report['tenant_ids'],
            'period' => $period,
            'summary' => $calculator->calculate($report['report'], $mappings),
        ];
    }

    public function profitLossRange(array $range, array $tenantIds): array
    {
        $period = $this->reports->profitLossRangePeriod($range);
        $sources = $this->tenants->runReports($tenantIds, function () use ($range) {
            return [
                'payload' => $this->reports->profitLossRange($range)['report'],
                'definitions' => $this->definitions(),
            ];
        });
        $data = $this->mergeSources($sources, ['revenue' => 'REVENUE', 'expense' => 'EXPENSE']);

        return [
            'tenant_ids' => array_column($sources, 'tenant_id'),
            'period' => $period,
            'report' => [
                'data' => $data,
                'net_income' => round($this->sumRoots($data['revenue']) - $this->sumRoots($data['expense']), 2),
            ],
        ];
    }

    public function profitLossSummaryRange(array $range, array $tenantIds): array
    {
        $period = $this->reports->profitLossRangePeriod($range);
        $calculator = app(ProfitLossSummaryCalculator::class);
        $mappings = $calculator->mappings();
        $report = $this->profitLossRange($range, $tenantIds);

        return [
            'tenant_ids' => $report['tenant_ids'],
            'period' => $period,
            'summary' => $calculator->calculate($report['report'], $mappings),
        ];
    }

    public function profitLossSummaryByTenant($year, $month, array $tenantIds): array
    {
        return $this->tenantSummaryTotals(
            app(ProfitLossSummaryService::class)->summarize($year, $month, $tenantIds)
        );
    }

    public function profitLossSummaryRangeByTenant(array $range, array $tenantIds): array
    {
        return $this->tenantSummaryTotals(
            app(ProfitLossSummaryService::class)->summarizeRange($range, $tenantIds)
        );
    }

    /**
     * Consolidated month-end position and dashboard metrics. Snapshot-derived values are null
     * unless every tenant snapshot is current; ratios use consolidated amounts.
     */
    public function balanceSheetSummary($year, $month, array $tenantIds): array
    {
        $period = app(ProfitLossSummaryCalculator::class)->period($year, $month);
        [$months, $mappings] = $this->balanceSheetSummaryInputs([$period]);
        $sources = $this->balanceSheetSources($months, $tenantIds);

        return [
            'tenant_ids' => array_column($sources, 'tenant_id'),
            'period' => $period,
            'current_year_period' => $months[0]['current_year_period'],
            'summary' => $this->consolidatedBalanceSheetSummary($sources, 0, $mappings),
        ];
    }

    /** Separate month-end position and metrics per tenant; never combines tenant amounts. */
    public function balanceSheetSummaryByTenant($year, $month, array $tenantIds): array
    {
        $period = app(ProfitLossSummaryCalculator::class)->period($year, $month);
        [$months, $mappings] = $this->balanceSheetSummaryInputs([$period]);
        $sources = $this->balanceSheetSources($months, $tenantIds);

        return [
            'tenant_ids' => array_column($sources, 'tenant_id'),
            'period' => $period,
            'current_year_period' => $months[0]['current_year_period'],
            'summaries' => array_map(function (array $source) use ($mappings) {
                $month = $source['report']['months'][0];

                return ['tenant_id' => $source['tenant_id'], 'status' => $month['status']]
                    + $this->balanceSheetMetrics($month['payload']['data'] ?? null, (float) $month['profit_loss']['net_income'], $mappings);
            }, $sources),
        ];
    }

    /** One consolidated month-end summary per month in the inclusive range; months are never added together. */
    public function balanceSheetSummaryByMonth(array $range, array $tenantIds): array
    {
        $period = $this->reports->profitLossRangePeriod($range);
        $months = [];
        for ($index = $period['start_year'] * 12 + $period['start_month'] - 1;
            $index <= $period['end_year'] * 12 + $period['end_month'] - 1; $index++) {
            $months[] = ['year' => intdiv($index, 12), 'month' => $index % 12 + 1];
        }
        [$months, $mappings] = $this->balanceSheetSummaryInputs($months);
        $sources = $this->balanceSheetSources($months, $tenantIds);

        return [
            'tenant_ids' => array_column($sources, 'tenant_id'),
            'period' => $period,
            'summaries' => array_map(fn (array $month, int $position) => [
                'period' => ['year' => $month['year'], 'month' => $month['month']],
                'current_year_period' => $month['current_year_period'],
                'summary' => $this->consolidatedBalanceSheetSummary($sources, $position, $mappings),
            ], $months, array_keys($months)),
        ];
    }

    /**
     * Informational consolidated profit/loss for a balance-sheet month: posted movements before
     * the month and within it, excluding opening and profit-transfer closing journals. Not part of,
     * and never added to, balance-sheet equity totals.
     */
    public function balanceSheetProfitDetail($year, $month, array $tenantIds): array
    {
        $period = app(ProfitLossSummaryCalculator::class)->period($year, $month);
        $sources = $this->profitDetailSources([$period], $tenantIds);

        return [
            'tenant_ids' => array_column($sources, 'tenant_id'),
            'period' => $period,
            'summary' => $this->consolidatedProfitDetail($sources, 0),
        ];
    }

    /** Separate informational profit/loss per tenant; never combines tenant amounts. */
    public function balanceSheetProfitDetailByTenant($year, $month, array $tenantIds): array
    {
        $period = app(ProfitLossSummaryCalculator::class)->period($year, $month);
        $sources = $this->profitDetailSources([$period], $tenantIds);

        return [
            'tenant_ids' => array_column($sources, 'tenant_id'),
            'period' => $period,
            'summaries' => array_map(fn (array $source) => ['tenant_id' => $source['tenant_id']] + $source['report'][0], $sources),
        ];
    }

    /** One consolidated profit detail per month in the inclusive range, in chronological order. */
    public function balanceSheetProfitDetailByMonth(array $range, array $tenantIds): array
    {
        $period = $this->reports->profitLossRangePeriod($range);
        $months = [];
        for ($index = $period['start_year'] * 12 + $period['start_month'] - 1;
            $index <= $period['end_year'] * 12 + $period['end_month'] - 1; $index++) {
            $months[] = ['year' => intdiv($index, 12), 'month' => $index % 12 + 1];
        }
        $sources = $this->profitDetailSources($months, $tenantIds);

        return [
            'tenant_ids' => array_column($sources, 'tenant_id'),
            'period' => $period,
            'summaries' => array_map(fn (array $month, int $position) => [
                'period' => $month,
                'summary' => $this->consolidatedProfitDetail($sources, $position),
            ], $months, array_keys($months)),
        ];
    }

    /** Validate closing markers before any tenant is initialized, then run each tenant once. */
    protected function profitDetailSources(array $months, array $tenantIds): array
    {
        $this->reports->profitLossDetailMarkers();

        return $this->tenants->runReports($tenantIds, fn () => array_map(
            fn (array $month) => $this->reports->profitLossDetail($month['year'], $month['month']),
            $months
        ));
    }

    protected function consolidatedProfitDetail(array $sources, int $position): array
    {
        $summary = [];
        foreach (['prior_months_profit_loss', 'current_month_profit_loss'] as $key) {
            $summary[$key] = round(array_sum(array_map(fn (array $source) => $source['report'][$position][$key], $sources)), 2);
        }

        return $summary;
    }

    /**
     * Validate host configuration before any tenant is initialized and attach each month's
     * fiscal-year-to-date range (accounting.fiscal.start_month through the reporting month).
     */
    protected function balanceSheetSummaryInputs(array $months): array
    {
        $mappings = $this->balanceSheetMappings();
        $startMonth = config('accounting.fiscal.start_month', 1);
        validator(['fiscal_start_month' => $startMonth], [
            'fiscal_start_month' => ['required', 'integer', 'between:1,12'],
        ])->validate();
        $startMonth = (int) $startMonth;

        return [array_map(fn (array $month) => $month + ['current_year_period' => [
            'start_year' => $month['month'] >= $startMonth ? $month['year'] : $month['year'] - 1,
            'start_month' => $startMonth,
            'end_year' => $month['year'],
            'end_month' => $month['month'],
        ]], $months), $mappings];
    }

    /**
     * Stable code => current|non_current for asset and liability accounts. Account overrides win
     * over the nearest mapped ancestor category; unmapped accounts are non-current.
     */
    protected function balanceSheetMappings(): array
    {
        $invalid = fn (string $message) => throw ValidationException::withMessages(['balance_sheet_mappings' => [$message]]);
        $configured = config('accounting.balance_sheet_summary', []);
        if (! is_array($configured)) {
            $invalid('Balance-sheet summary mappings must be an array.');
        }

        $mappings = [];
        foreach (self::DEFAULT_BALANCE_SHEET_MAPPINGS as $kind => $defaults) {
            $overrides = array_key_exists($kind, $configured) ? $configured[$kind] : [];
            if (! is_array($overrides)) {
                $invalid('Balance-sheet summary '.$kind.' must be an array.');
            }
            foreach ($overrides as $code => $classification) {
                if (trim((string) $code) === '' || ! in_array($classification, ['current', 'non_current'], true)) {
                    $invalid('Invalid balance-sheet classification for '.$kind.' code ['.$code.'].');
                }
            }
            $mappings[$kind] = array_replace($defaults, $overrides);
        }

        return $mappings;
    }

    /**
     * Run every tenant once: definitions, snapshot statuses, the existing balance-sheet report for
     * each usable month (missing/stale months are not queried for amounts), and fiscal-year-to-date
     * profit/loss from posted journals via the existing profit/loss range report.
     */
    protected function balanceSheetSources(array $months, array $tenantIds): array
    {
        return $this->tenants->runReports($tenantIds, function () use ($months) {
            $statuses = $this->balanceSheetSnapshotStatuses($months);

            return [
                'definitions' => $this->definitions(),
                'months' => array_map(fn (array $month, string $status) => [
                    'status' => $status,
                    'payload' => $status === 'current' ? $this->reports->balanceSheet($month['year'], $month['month']) : null,
                    'profit_loss' => $this->reports->profitLossRange($month['current_year_period'])['report'],
                ], $months, $statuses),
            ];
        });
    }

    /**
     * Classify each month's snapshot in the active transaction database:
     * - current: the month is closed and every month from the earliest posted journal
     *   through it is closed in chronological closed_at order (carry-forward intact),
     *   with no legacy-signed snapshot in that chain;
     * - stale: snapshots exist for an open (reopened) month, or a closed month's carry-forward
     *   chain has an open month, an earlier month closed after it, or a legacy-signed snapshot;
     * - missing: the month is not closed and has no snapshots.
     */
    protected function balanceSheetSnapshotStatuses(array $months): array
    {
        $state = $this->snapshotChainState();

        return array_map(
            fn (array $month) => $this->snapshotChainStatus($this->monthIndex($month['year'], $month['month']), $state)[0],
            $months
        );
    }

    /**
     * Fiscal periods, snapshot row counts, legacy-signed months (keyed by month index) and the
     * earliest posted journal month of the active tenant, from central copies in central mode.
     */
    protected function snapshotChainState(): array
    {
        $central = app(CentralReportContext::class);
        $periods = ($central->fiscalPeriods() ?? FiscalPeriod::query()->get())
            ->keyBy(fn (FiscalPeriod $period) => $this->monthIndex($period->year, $period->month));
        $snapshots = ($central->snapshotCounts() ?? MonthlyBalance::query()
            ->selectRaw('fiscal_year, fiscal_month, COUNT(*) as snapshot_count')
            ->groupBy('fiscal_year', 'fiscal_month')->get())
            ->mapWithKeys(fn ($row) => [$this->monthIndex($row->fiscal_year, $row->fiscal_month) => (int) $row->snapshot_count]);
        $legacy = $this->legacySignedSnapshotMonths()
            ->mapWithKeys(fn ($row) => [$this->monthIndex($row->fiscal_year, $row->fiscal_month) => true]);
        $earliest = $central->active()
            ? $central->earliestPostedDate()
            : JournalEntry::query()->where('status', JournalStatus::POSTED->value)->min('trx_date');
        $earliest = $earliest === null ? null : $this->monthIndex(Carbon::parse($earliest)->year, Carbon::parse($earliest)->month);

        return ['periods' => $periods, 'snapshots' => $snapshots, 'legacy' => $legacy, 'earliest' => $earliest];
    }

    /**
     * [status, reason] of one month index under the balanceSheetSnapshotStatuses() rules. Reasons:
     * reopened (open month that retains snapshot rows), legacy_sign (a legacy-signed snapshot in the
     * carry-forward chain) or broken_chain (an unclosed or out-of-order month in that chain).
     */
    protected function snapshotChainStatus(int $target, array $state): array
    {
        $period = $state['periods']->get($target);
        if (! $period || ! $period->is_closed) {
            return $state['snapshots']->get($target, 0) > 0 ? ['stale', 'reopened'] : ['missing', null];
        }
        $previousClosedAt = null;
        $earliest = $state['earliest'];
        for ($index = $earliest === null ? $target : min($earliest, $target); $index <= $target; $index++) {
            $link = $state['periods']->get($index);
            if ($state['legacy']->has($index)) {
                return ['stale', 'legacy_sign'];
            }
            if (! $link || ! $link->is_closed
                || ($previousClosedAt && $link->closed_at && $link->closed_at->lt($previousClosedAt))) {
                return ['stale', 'broken_chain'];
            }
            $previousClosedAt = $link->closed_at ?? $previousClosedAt;
        }

        return ['current', null];
    }

    protected function monthIndex($year, $month): int
    {
        return (int) $year * 12 + (int) $month - 1;
    }

    /**
     * Months whose ASSET/EXPENSE snapshot rows were written credit-normal by closeMonth() before
     * its case-insensitive type fix: ending = opening + credit - debit with non-zero net activity.
     * Rows seeded without activity totals never match. Uses the same account/category sources as
     * closing; account ids are chunked because accounts may live on a separate master connection.
     */
    protected function legacySignedSnapshotMonths(): Collection
    {
        $central = app(CentralReportContext::class);
        $types = $this->categories->allOrdered()->pluck('type', 'id');
        $debitNormal = Account::query()->get(['id', 'category_id'])
            ->filter(fn (Account $account) => in_array(strtoupper((string) $types->get($account->category_id)), ['ASSET', 'EXPENSE'], true))
            ->modelKeys();
        if ($central->active()) {
            return $central->legacySignedSnapshotMonths($debitNormal);
        }

        return collect($debitNormal)->chunk(500)->flatMap(fn (Collection $ids) => MonthlyBalance::query()
            ->whereIn('account_id', $ids->values()->all())
            ->whereRaw('ABS(total_debit - total_credit) >= 0.005')
            ->whereRaw('ABS(ending_balance - (opening_balance + total_credit - total_debit)) < 0.005')
            ->distinct()->get(['fiscal_year', 'fiscal_month'])
            ->all());
    }

    protected function consolidatedBalanceSheetSummary(array $sources, int $position, array $mappings): array
    {
        $snapshots = array_map(fn (array $source) => [
            'tenant_id' => $source['tenant_id'],
            'status' => $source['report']['months'][$position]['status'],
        ], $sources);
        $statuses = array_column($snapshots, 'status');
        $status = in_array('missing', $statuses, true) ? 'missing' : (in_array('stale', $statuses, true) ? 'stale' : 'current');
        $merge = fn (string $key, array $groups) => $this->mergeSources(array_map(fn (array $source) => [
            'tenant_id' => $source['tenant_id'],
            'report' => [
                'payload' => $source['report']['months'][$position][$key],
                'definitions' => $source['report']['definitions'],
            ],
        ], $sources), $groups);
        $profitLoss = $merge('profit_loss', ['revenue' => 'REVENUE', 'expense' => 'EXPENSE']);
        $balanceSheet = $status === 'current'
            ? $merge('payload', ['asset' => 'ASSET', 'liability' => 'LIABILITY', 'equity' => 'EQUITY'])
            : null;

        return ['status' => $status]
            + $this->balanceSheetMetrics(
                $balanceSheet,
                $this->sumRoots($profitLoss['revenue']) - $this->sumRoots($profitLoss['expense']),
                $mappings
            )
            + ['snapshots' => $snapshots];
    }

    /**
     * Dashboard metrics from one balance-sheet tree (a tenant payload or a consolidated merge).
     * Snapshot-derived values are null when $data is null. Current-year profit/loss comes from
     * posted journals and is added to liabilities + equity exactly once: equity totals contain
     * only EQUITY categories, and journals that close profit into equity also post to the
     * revenue/expense accounts, reducing the journal-based profit by the transferred amount.
     */
    protected function balanceSheetMetrics(?array $data, float $currentYearProfitLoss, array $mappings): array
    {
        $profitLoss = round($currentYearProfitLoss, 2);
        if ($data === null) {
            return array_fill_keys([
                'total_assets', 'total_liabilities', 'total_equity',
                'current_assets', 'current_liabilities', 'current_ratio', 'debt_ratio',
            ], null) + ['current_year_profit_loss' => $profitLoss, 'balance_sheet_difference' => null];
        }

        $assets = $this->sumRoots($data['asset']);
        $liabilities = $this->sumRoots($data['liability']);
        $equity = $this->sumRoots($data['equity']);
        $currentAssets = $this->currentBalance($data['asset'], $mappings);
        $currentLiabilities = $this->currentBalance($data['liability'], $mappings);

        return [
            'total_assets' => $assets,
            'total_liabilities' => $liabilities,
            'total_equity' => $equity,
            'current_assets' => $currentAssets,
            'current_liabilities' => $currentLiabilities,
            'current_ratio' => $currentLiabilities == 0.0 ? null : round($currentAssets / $currentLiabilities, 2),
            'debt_ratio' => $assets == 0.0 ? null : round($liabilities / $assets * 100, 2),
            'current_year_profit_loss' => $profitLoss,
            'balance_sheet_difference' => round($assets - ($liabilities + $equity + $profitLoss), 2),
        ];
    }

    /** Sum account balances classified current (account mapping, else nearest mapped ancestor category). */
    protected function currentBalance(array $nodes, array $mappings): float
    {
        $total = 0.0;
        $walk = function (array $node, ?string $inherited) use (&$walk, &$total, $mappings): void {
            $classification = $mappings['category_mappings'][$this->code($node['category_code'] ?? null, 'category')] ?? $inherited;
            foreach ($node['accounts'] as $account) {
                $code = $this->code($account['code'] ?? null, 'account');
                if (($mappings['account_mappings'][$code] ?? $classification) === 'current') {
                    $total += (float) $account['balance'];
                }
            }
            foreach ($node['children'] as $child) {
                $walk($child, $classification);
            }
        };
        foreach ($nodes as $node) {
            $walk($node, null);
        }

        return round($total, 2);
    }

    protected function tenantSummaryTotals(array $result): array
    {
        $result['summaries'] = array_map(fn (array $entry) => [
            'tenant_id' => $entry['tenant_id'],
            'net_sales' => $entry['summary']['net_sales'],
            'gross_profit' => $entry['summary']['gross_profit'],
            'operating_profit' => $entry['summary']['operating_profit'],
            'net_profit' => $entry['summary']['net_profit'],
        ], $result['summaries']);

        return $result;
    }

    /**
     * Merge the visible chart before category filters and pagination are applied.
     */
    public function categoryTree(array $tenantIds, ?int $year = null, ?int $month = null, bool $withBalance = false): array
    {
        // read_source = central: master tables plus central snapshot/journal copies, no tenant initialization.
        $sources = $this->tenants->runReports($tenantIds, function () use ($year, $month, $withBalance) {
            // Central mode names the tenant being read without initializing tenancy.
            $tenantId = app(CentralReportContext::class)->tenantId() ?? $this->categoryTenantIdentifier();
            $categories = $this->categories->allOrdered();
            $accounts = $this->accounts->visibleOrdered($tenantId);
            $balances = $withBalance
                ? app(AccountBalanceService::class)->getBalances($accounts->pluck('id')->all(), $year, $month)
                : collect();
            $ordering = [];
            $aliases = [];
            $descriptions = [];
            foreach ($categories as $category) {
                $ordering['category:'.$category->category_code] = (int) $category->sequence_no;
                $aliases[(string) $category->id] = (string) $category->category_code;
            }
            foreach ($accounts as $account) {
                $account->setAttribute('balance', (float) data_get($balances->get($account->id), 'ending_balance', 0));
                $descriptions['account:'.$account->code] = $account->description;
            }

            return [
                'payload' => ['data' => ['categories' => $this->categories->buildTree($categories, $accounts)->all()]],
                'definitions' => $this->definitionsFor($categories, $accounts),
                'ordering' => $ordering,
                'aliases' => $aliases,
                'descriptions' => $descriptions,
            ];
        });

        $ordering = [];
        $aliases = [];
        $descriptions = [];
        foreach ($sources as $source) {
            foreach ($source['report']['ordering'] as $code => $sequence) {
                $ordering[$code] ??= $sequence;
            }
            foreach ($source['report']['aliases'] as $id => $code) {
                $aliases[$id] = array_values(array_unique(array_merge($aliases[$id] ?? [], [$code])));
            }
            foreach ($source['report']['descriptions'] as $code => $description) {
                if (! array_key_exists($code, $descriptions)) {
                    $descriptions[$code] = $description;
                }
            }
        }
        $data = $this->mergeSources($sources, ['categories' => null], $ordering);
        $decorate = function (array $nodes) use (&$decorate, $ordering, $descriptions): array {
            return array_map(function (array $node) use ($decorate, $ordering, $descriptions) {
                $node['sequence_no'] = $ordering['category:'.$node['category_code']] ?? 0;
                $node['accounts'] = array_map(function (array $account) use ($descriptions) {
                    return $account + ['description' => $descriptions['account:'.$account['code']] ?? null];
                }, $node['accounts']);
                $node['children'] = $decorate($node['children']);

                return $node;
            }, $nodes);
        };

        return [
            'tenant_ids' => array_column($sources, 'tenant_id'),
            'data' => $decorate($data['categories']),
            'category_aliases' => $aliases,
        ];
    }

    /**
     * Hierarchical account report for one month: opening balance, posted debit/credit movements and
     * ending balance on every account and category, consolidated across tenants by stable codes.
     * Amounts come from AccountBalanceService (current snapshots, or posted journals for months
     * without snapshots). When any tenant's month is stale every amount is null; see
     * docs/reference.md ("Category balance tree") for statuses, reasons and sign handling.
     */
    public function categoryBalanceTree(int $year, int $month, array $tenantIds): array
    {
        $period = app(ProfitLossSummaryCalculator::class)->period($year, $month);

        // read_source = central: master tables plus central snapshot/journal copies, no tenant initialization.
        $sources = $this->tenants->runReports($tenantIds, function () use ($period) {
            $tenantId = app(CentralReportContext::class)->tenantId() ?? $this->categoryTenantIdentifier();
            $categories = $this->categories->allOrdered();
            $accounts = $this->accounts->visibleOrdered($tenantId);
            $definitions = $this->definitionsFor($categories, $accounts);
            [$status, $reason] = $this->categoryBalanceSnapshotStatus($period['year'], $period['month']);
            // Stale months are not queried for amounts; the merged result withholds them.
            $balances = $status === 'stale'
                ? collect()
                : app(AccountBalanceService::class)->getBalances($accounts->pluck('id')->all(), $period['year'], $period['month']);
            $ordering = [];
            foreach ($categories as $category) {
                $ordering['category:'.$category->category_code] = (int) $category->sequence_no;
            }
            $amounts = [];
            foreach ($accounts as $account) {
                $row = $balances->get($account->id, []);
                $values = [];
                foreach (self::BALANCE_FIELDS as $field) {
                    $values[$field] = round((float) data_get($row, $field, 0), 2);
                }
                $amounts['account:'.$account->code] = $values;
                $account->setAttribute('balance', $values['ending_balance']);
            }

            return [
                'payload' => ['data' => ['categories' => $this->categories->buildTree($categories, $accounts)->all()]],
                'definitions' => $definitions,
                'ordering' => $ordering,
                'amounts' => $amounts,
                'snapshot' => ['status' => $status, 'reason' => $reason],
            ];
        });

        // Context is restored; the existing merge validates hierarchy and code compatibility.
        $ordering = [];
        $totals = [];
        foreach ($sources as $source) {
            foreach ($source['report']['ordering'] as $code => $sequence) {
                $ordering[$code] ??= $sequence;
            }
            foreach ($source['report']['amounts'] as $key => $values) {
                foreach ($values as $field => $amount) {
                    $totals[$key][$field] = round(($totals[$key][$field] ?? 0.0) + $amount, 2);
                }
            }
        }
        $data = $this->mergeSources($sources, ['categories' => null], $ordering)['categories'];
        $nodes = array_map(fn (array $node) => $this->balanceTreeNode($node, $totals, $ordering), $data);

        $snapshots = array_map(fn (array $source) => ['tenant_id' => $source['tenant_id']] + $source['report']['snapshot'], $sources);
        $statuses = array_column($snapshots, 'status');
        $status = in_array('stale', $statuses, true) ? 'stale' : (in_array('open', $statuses, true) ? 'open' : 'current');
        if ($status === 'stale') {
            $nodes = $this->withheldBalanceNodes($nodes);
        }

        return [
            'tenant_ids' => array_column($sources, 'tenant_id'),
            'period' => $period,
            'status' => $status,
            'snapshots' => $snapshots,
            'data' => $nodes,
        ];
    }

    /**
     * [status, reason] of the requested month in the active tenant for categoryBalanceTree():
     * - current: closed with an intact carry-forward chain; amounts are the stored snapshots;
     * - open: no snapshot rows, so getBalances() calculates from posted journals on top of the latest
     *   earlier snapshot month, which must itself be current (or absent);
     * - stale: the month or that carry-forward base is reopened, legacy-signed or has a broken chain.
     */
    protected function categoryBalanceSnapshotStatus(int $year, int $month): array
    {
        $state = $this->snapshotChainState();
        $target = $this->monthIndex($year, $month);
        [$status, $reason] = $this->snapshotChainStatus($target, $state);
        if ($status !== 'missing') {
            return [$status, $reason];
        }
        $base = $state['snapshots']->keys()->filter(fn ($index) => $index < $target)->max();
        if ($base === null) {
            return ['open', null];
        }
        [$baseStatus, $baseReason] = $this->snapshotChainStatus((int) $base, $state);

        return $baseStatus === 'current' ? ['open', null] : ['stale', $baseReason];
    }

    /**
     * One merged category with balance fields. Accounts carry their category's normal balance.
     * A child category of the opposite normal balance is converted to this category's side before
     * it is added: opening/ending change sign, debit/credit movements are added unchanged, so
     * ending = opening + debit - credit (debit-normal) or opening + credit - debit holds per node.
     */
    protected function balanceTreeNode(array $node, array $totals, array $ordering): array
    {
        $side = $this->normalBalance($node);
        $sum = array_fill_keys(self::BALANCE_FIELDS, 0.0);
        $accounts = [];
        foreach ($node['accounts'] as $account) {
            unset($account['balance']);
            $values = $totals['account:'.$account['code']];
            $accounts[] = $account + ['normal_balance' => $side] + $values;
            $sum = $this->addBalances($sum, $values, false);
        }
        $children = [];
        foreach ($node['children'] as $child) {
            $child = $this->balanceTreeNode($child, $totals, $ordering);
            $children[] = $child;
            $sum = $this->addBalances($sum, $child, $child['normal_balance'] !== $side);
        }
        unset($node['balance'], $node['accounts'], $node['children']);

        return $node + [
            'sequence_no' => $ordering['category:'.$node['category_code']] ?? 0,
            'normal_balance' => $side,
        ] + $sum + ['accounts' => $accounts, 'children' => $children];
    }

    protected function addBalances(array $sum, array $values, bool $opposite): array
    {
        $sign = $opposite ? -1 : 1;

        return [
            'opening_balance' => round($sum['opening_balance'] + $sign * $values['opening_balance'], 2),
            'total_debit' => round($sum['total_debit'] + $values['total_debit'], 2),
            'total_credit' => round($sum['total_credit'] + $values['total_credit'], 2),
            'ending_balance' => round($sum['ending_balance'] + $sign * $values['ending_balance'], 2),
        ];
    }

    /** debit for ASSET/EXPENSE, credit for LIABILITY/EQUITY/REVENUE; other types are rejected. */
    protected function normalBalance(array $node): string
    {
        $type = strtoupper((string) $node['type']);
        if (in_array($type, ['ASSET', 'EXPENSE'], true)) {
            return 'debit';
        }
        if (in_array($type, ['LIABILITY', 'EQUITY', 'REVENUE'], true)) {
            return 'credit';
        }

        $this->invalid('Category ['.$node['category_code'].'] has unsupported type ['.$node['type'].'] for a normal balance.');
    }

    protected function withheldBalanceNodes(array $nodes): array
    {
        return array_map(function (array $node) {
            $node = array_replace($node, array_fill_keys(self::BALANCE_FIELDS, null));
            $node['accounts'] = array_map(fn (array $account) => array_replace($account, array_fill_keys(self::BALANCE_FIELDS, null)), $node['accounts']);
            $node['children'] = $this->withheldBalanceNodes($node['children']);

            return $node;
        }, $nodes);
    }

    protected function categoryTenantIdentifier(): string
    {
        if (! function_exists('tenancy') || ! (tenancy()->initialized ?? false)
            || ! ((tenancy()->tenant ?? null) instanceof Model)) {
            throw ValidationException::withMessages([
                'tenant_ids' => ['Category consolidation requires an initialized tenancy integration.'],
            ]);
        }

        return (string) tenancy()->tenant->getKey();
    }

    protected function consolidate(string $method, array $groups, $year, $month, array $tenantIds): array
    {
        validator(['year' => $year, 'month' => $month], [
            'year' => ['required', 'integer', 'min:1'],
            'month' => ['required', 'integer', 'between:1,12'],
        ])->validate();

        $sources = $this->tenants->runReports($tenantIds, function () use ($method, $year, $month) {
            return [
                'payload' => $this->reports->{$method}($year, $month),
                // Read metadata in the same context, including definitions
                // outside this report that might reuse a participating code.
                'definitions' => $this->definitions(),
            ];
        });

        $data = $this->mergeSources($sources, $groups);

        $report = ['data' => $data];
        if ($method === 'profitLoss') {
            $report['net_income'] = round($this->sumRoots($data['revenue']) - $this->sumRoots($data['expense']), 2);
        } else {
            foreach (['asset' => 'total_assets', 'liability' => 'total_liabilities', 'equity' => 'total_equity'] as $group => $total) {
                $report[$total] = $this->sumRoots($data[$group]);
            }
        }

        return [
            'tenant_ids' => array_column($sources, 'tenant_id'),
            'period' => ['year' => (int) $year, 'month' => (int) $month],
            'report' => $report,
        ];
    }

    protected function mergeSources(array $sources, array $groups, ?array $ordering = null): array
    {
        $categories = [];
        $accounts = [];
        $balances = [];
        foreach ($sources as $source) {
            $seenCategories = [];
            $seenAccounts = [];
            foreach ($groups as $group => $type) {
                foreach ($source['report']['payload']['data'][$group] as $node) {
                    $this->collectNode(
                        $node, null, $source, $type ?? $node['type'],
                        $categories, $accounts, $balances, $seenCategories, $seenAccounts
                    );
                }
            }
            if (in_array(null, $groups, true)
                && (count($seenCategories) !== count($source['report']['definitions']['categories'])
                    || count($seenAccounts) !== count($source['report']['definitions']['accounts']))) {
                $this->invalid('The category chart has unreachable categories or accounts in tenant ['.$source['tenant_id'].'].');
            }
        }

        // Check every occurrence of a participating code, even if another
        // tenant classifies it outside the requested report. Absence is valid.
        foreach ($sources as $source) {
            foreach (['categories' => $categories, 'accounts' => $accounts] as $kind => $definitions) {
                foreach ($definitions as $key => $definition) {
                    if (isset($source['report']['definitions'][$kind][$key])) {
                        $this->assertCompatible($kind, $definition, $source['report']['definitions'][$kind][$key], $source['tenant_id']);
                    }
                }
            }
        }

        uasort($categories, function (array $a, array $b) use ($ordering) {
            if ($ordering !== null) {
                $sequence = ($ordering['category:'.$a['category_code']] ?? 0) <=> ($ordering['category:'.$b['category_code']] ?? 0);
                if ($sequence !== 0) {
                    return $sequence;
                }
                $name = strcmp($a['category_name'], $b['category_name']);
                if ($name !== 0) {
                    return $name;
                }
            }

            return strcmp($a['category_code'], $b['category_code']);
        });
        uasort($accounts, fn (array $a, array $b) => strcmp($a['code'], $b['code']));
        $children = [];
        $accountsByCategory = [];
        foreach ($categories as $definition) {
            $parent = $definition['parent_code'];
            $children[$parent === null ? '__root__' : 'category:'.$parent][] = $definition;
        }
        foreach ($accounts as $key => $definition) {
            $accountsByCategory['category:'.$definition['category_code']][] = $definition + ['balance' => $balances[$key]];
        }

        $data = [];
        foreach ($groups as $group => $type) {
            $data[$group] = [];
            foreach ($children['__root__'] ?? [] as $root) {
                if ($type === null || $root['type'] === $type) {
                    $data[$group][] = $this->buildNode($root, $children, $accountsByCategory);
                }
            }
        }

        return $data;
    }

    protected function definitions(): array
    {
        return $this->definitionsFor($this->categories->allOrdered(), $this->accounts->allOrdered());
    }

    protected function definitionsFor(Collection $categoryRecords, Collection $accountRecords): array
    {
        $records = $categoryRecords->keyBy('id');
        $categories = [];
        foreach ($records as $record) {
            $code = $this->code($record->category_code, 'category');
            $parent = $record->parent_id === null ? null : $records->get($record->parent_id);
            if ($record->parent_id !== null && ! $parent) {
                $this->invalid('Category ['.$code.'] has an unresolved parent.');
            }
            $key = 'category:'.$code;
            if (isset($categories[$key])) {
                $this->invalid('Duplicate category code ['.$code.'] in a tenant definition.');
            }
            $categories[$key] = [
                'category_code' => $code,
                'parent_code' => $parent ? $this->code($parent->category_code, 'category') : null,
                'type' => $record->type,
                'category_name' => $record->category_name,
                'report_type' => $record->report_type,
                'status' => (bool) $record->status,
            ];
        }

        $accounts = [];
        foreach ($accountRecords as $record) {
            $code = $this->code($record->code, 'account');
            $category = $records->get($record->category_id);
            if (! $category) {
                $this->invalid('Account ['.$code.'] has an unresolved category.');
            }
            $key = 'account:'.$code;
            if (isset($accounts[$key])) {
                $this->invalid('Duplicate account code ['.$code.'] in a tenant definition.');
            }
            $accounts[$key] = [
                'code' => $code,
                'category_code' => $this->code($category->category_code, 'category'),
                'name' => $record->name,
                'is_postable' => (bool) $record->is_postable,
                'status' => (bool) $record->status,
            ];
        }

        return ['categories' => $categories, 'accounts' => $accounts];
    }

    protected function collectNode(
        array $node, ?string $parentCode, array $source, string $rootType,
        array &$categories, array &$accounts, array &$balances,
        array &$seenCategories, array &$seenAccounts
    ): void {
        $code = $this->code($node['category_code'] ?? null, 'category');
        $key = 'category:'.$code;
        $definition = $source['report']['definitions']['categories'][$key] ?? null;
        if (! $definition || $definition['parent_code'] !== $parentCode
            || ($parentCode === null && $definition['type'] !== $rootType)) {
            $this->invalid('Category ['.$code.'] does not match its report hierarchy in tenant ['.$source['tenant_id'].'].');
        }
        if (isset($seenCategories[$key])) {
            $this->invalid('Category ['.$code.'] occurs more than once in tenant ['.$source['tenant_id'].'].');
        }
        $seenCategories[$key] = true;
        $categories[$key] ??= $definition;

        foreach ($node['accounts'] as $account) {
            $accountCode = $this->code($account['code'] ?? null, 'account');
            $accountKey = 'account:'.$accountCode;
            $accountDefinition = $source['report']['definitions']['accounts'][$accountKey] ?? null;
            if (! $accountDefinition || $accountDefinition['category_code'] !== $code) {
                $this->invalid('Account ['.$accountCode.'] does not match its report category in tenant ['.$source['tenant_id'].'].');
            }
            if (isset($seenAccounts[$accountKey])) {
                $this->invalid('Account ['.$accountCode.'] occurs more than once in tenant ['.$source['tenant_id'].'].');
            }
            $amount = $account['balance'] ?? null;
            if (! is_numeric($amount) || ! is_finite((float) $amount)) {
                $this->invalid('Account ['.$accountCode.'] has an invalid balance in tenant ['.$source['tenant_id'].'].');
            }
            $seenAccounts[$accountKey] = true;
            $accounts[$accountKey] ??= $accountDefinition;
            $balances[$accountKey] = round(($balances[$accountKey] ?? 0.0) + (float) $amount, 2);
        }

        foreach ($node['children'] as $child) {
            $this->collectNode($child, $code, $source, $rootType, $categories, $accounts, $balances, $seenCategories, $seenAccounts);
        }
    }

    protected function assertCompatible(string $kind, array $expected, array $actual, string $tenantId): void
    {
        foreach ($expected as $field => $value) {
            if ($actual[$field] !== $value) {
                $code = $expected['category_code'];
                if ($kind === 'accounts') {
                    $code = $expected['code'];
                }
                $label = $kind === 'accounts' ? 'Account' : 'Category';
                $this->invalid($label.' code ['.$code.'] has incompatible ['.$field.'] in tenant ['.$tenantId.'].');
            }
        }
    }

    protected function buildNode(array $definition, array $children, array $accountsByCategory): array
    {
        $key = 'category:'.$definition['category_code'];
        $childNodes = [];
        foreach ($children[$key] ?? [] as $child) {
            $childNodes[] = $this->buildNode($child, $children, $accountsByCategory);
        }
        $accounts = $accountsByCategory[$key] ?? [];

        return $definition + [
            'children' => $childNodes,
            'accounts' => $accounts,
            'balance' => round(array_sum(array_column($accounts, 'balance')) + $this->sumRoots($childNodes), 2),
        ];
    }

    protected function sumRoots(array $nodes): float
    {
        return round(array_sum(array_column($nodes, 'balance')), 2);
    }

    protected function code(mixed $value, string $kind): string
    {
        if ((! is_string($value) && ! is_int($value)) || trim((string) $value) === '') {
            $this->invalid('A '.$kind.' definition is missing a stable code.');
        }

        return (string) $value;
    }

    protected function invalid(string $message): never
    {
        throw ValidationException::withMessages(['consolidation' => [$message]]);
    }
}
