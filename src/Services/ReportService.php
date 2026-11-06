<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Enums\JournalStatus;
use ESolution\LaravelAccounting\Models\MonthlyBalance;
use ESolution\LaravelAccounting\Repositories\AccountCategoryRepository;
use ESolution\LaravelAccounting\Repositories\AccountRepository;
use ESolution\LaravelAccounting\Repositories\ServiceRepository;
use ESolution\LaravelAccounting\Support\AccountingTableResolver;
use ESolution\LaravelAccounting\Support\AccountingConnectionResolver;
use ESolution\LaravelAccounting\Support\CentralReportContext;
use ESolution\LaravelAccounting\Support\ProfitLossSummaryCalculator;
use ESolution\LaravelAccounting\Support\TenantReportRunner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportService
{
    /** Existing closing service codes (AccountingServiceCode) used as profit-transfer markers. */
    protected const DEFAULT_PROFIT_LOSS_DETAIL_MARKERS = [
        'closing_service_codes' => ['MONTH_END_CLOSING', 'YEAR_END_CLOSING'],
        'closing_source_types' => ['MONTH_END_CLOSING', 'YEAR_END_CLOSING'],
    ];

    public function __construct(
        protected AccountCategoryTreeService $treeService,
        protected AccountCategoryRepository $categories,
        protected AccountRepository $accounts,
        protected AccountingTableResolver $tables
    ) {
    }

    /**
     * General Ledger summary for legacy custom date-range callers.
     */
    public function generalLedger($accountId, $startDate, $endDate)
    {
        $account = $this->accounts->findById($accountId);

        if (! $account) {
            abort(404);
        }

        $category = $this->categories->findById($account->category_id);
        $transactionConnection = $this->transactionConnection();

        $startYear = date('Y', strtotime($startDate));
        $startMonth = date('m', strtotime($startDate));

        $openingBalance = MonthlyBalance::on($transactionConnection)
            ->where('account_id', $accountId)
            ->where('fiscal_year', $startYear)
            ->where('fiscal_month', (int) $startMonth)
            ->value('opening_balance') ?? 0;

        if (date('d', strtotime($startDate)) != '01') {
            $tablePrefix = $this->tables->tablePrefix();
            $rawTable = $this->tables->rawTable('journal_entry_details', $transactionConnection);

            $prevTransactions = DB::connection($transactionConnection)->table($tablePrefix.'journal_entry_details')
                ->join($tablePrefix.'journal_entries', $tablePrefix.'journal_entries.id', '=', $tablePrefix.'journal_entry_details.journal_entry_id')
                ->where($tablePrefix.'journal_entry_details.account_id', $accountId)
                ->where($tablePrefix.'journal_entries.trx_date', '>=', sprintf('%s-%s-01', $startYear, $startMonth))
                ->where($tablePrefix.'journal_entries.trx_date', '<', $startDate)
                ->where($tablePrefix.'journal_entries.status', 'posted')
                ->select(DB::raw("SUM({$rawTable}.debit) as total_debit, SUM({$rawTable}.credit) as total_credit"))
                ->first();

            if (in_array($category->type ?? 'ASSET', ['ASSET', 'EXPENSE'], true)) {
                $openingBalance += ($prevTransactions->total_debit ?? 0) - ($prevTransactions->total_credit ?? 0);
            } else {
                $openingBalance += ($prevTransactions->total_credit ?? 0) - ($prevTransactions->total_debit ?? 0);
            }
        }

        return [
            'account' => array_merge(
                $account->toArray(),
                [
                    'category_path' => $category ? $this->categories->buildLineage($category)->map(fn ($node) => $node->category_name)->all() : [],
                ]
            ),
            'opening_balance' => $openingBalance,
        ];
    }

    /**
     * Trial Balance Report
     * Menampilkan saldo semua account pada periode tertentu.
     */
    public function trialBalance($year, $month)
    {
        $tree = $this->buildTreeWithBalances($year, $month);

        return [
            'data' => $tree,
            'total_assets' => $this->sumByTypes($tree, ['ASSET']),
            'total_liabilities' => $this->sumByTypes($tree, ['LIABILITY']),
            'total_equity' => $this->sumByTypes($tree, ['EQUITY']),
        ];
    }

    /**
     * Profit & Loss Report
     * Menghitung laba/rugi perusahaan.
     */
    public function profitLoss($year, $month, ?array $tenantIds = null)
    {
        if ($tenantIds !== null) {
            return app(TenantReportRunner::class)->runReports($tenantIds, fn () => $this->profitLoss($year, $month));
        }

        $tree = $this->buildTreeWithBalances($year, $month);

        $revenue = $this->pickRootNodesByType($tree, ['REVENUE']);
        $expense = $this->pickRootNodesByType($tree, ['EXPENSE']);

        return [
            'data' => [
                'revenue' => $revenue,
                'expense' => $expense,
            ],
            'net_income' => $this->sumNodes($revenue) - $this->sumNodes($expense),
        ];
    }

    /**
     * Profit/loss movements for inclusive calendar months, without snapshots.
     */
    public function profitLossRange(array $range, ?array $tenantIds = null): array
    {
        $period = $this->profitLossRangePeriod($range);
        if ($tenantIds !== null) {
            $reports = app(TenantReportRunner::class)->runReports(
                $tenantIds, fn () => $this->buildProfitLossRangeReport($period)
            );

            return [
                'tenant_ids' => array_column($reports, 'tenant_id'),
                'period' => $period,
                'reports' => $reports,
            ];
        }

        return ['period' => $period, 'report' => $this->buildProfitLossRangeReport($period)];
    }

    public function profitLossRangePeriod(array $range): array
    {
        $validated = validator($range, [
            'start_year' => ['required', 'integer', 'min:1'],
            'start_month' => ['required', 'integer', 'between:1,12'],
            'end_year' => ['required', 'integer', 'min:1'],
            'end_month' => ['required', 'integer', 'between:1,12'],
        ])->validate();
        $normalized = array_map(fn ($value) => (int) $value, $validated);
        if ([$normalized['start_year'], $normalized['start_month']] > [$normalized['end_year'], $normalized['end_month']]) {
            throw ValidationException::withMessages([
                'end_month' => ['The end period must be on or after the start period.'],
            ]);
        }

        return [
            'year' => $normalized['end_year'],
            'month' => $normalized['end_month'],
        ] + $normalized;
    }

    /**
     * Balance Sheet Report
     * Menampilkan posisi keuangan perusahaan.
     */
    public function balanceSheet($year, $month, ?array $tenantIds = null)
    {
        if ($tenantIds !== null) {
            return app(TenantReportRunner::class)->runReports($tenantIds, fn () => $this->balanceSheet($year, $month));
        }

        $tree = $this->buildTreeWithBalances($year, $month);

        $asset = $this->pickRootNodesByType($tree, ['ASSET']);
        $liability = $this->pickRootNodesByType($tree, ['LIABILITY']);
        $equity = $this->pickRootNodesByType($tree, ['EQUITY']);

        return [
            'data' => [
                'asset' => $asset,
                'liability' => $liability,
                'equity' => $equity,
            ],
            'total_assets' => $this->sumNodes($asset),
            'total_liabilities' => $this->sumNodes($liability),
            'total_equity' => $this->sumNodes($equity),
        ];
    }

    /**
     * Informational profit/loss for a balance-sheet month, from posted revenue/expense movements:
     * everything dated before the month (all prior fiscal years included) and within the month.
     * Opening-balance and profit-transfer closing journals are excluded, so closing transfers into
     * equity do not erase the figures. Never added to balance-sheet equity.
     */
    public function profitLossDetail($year, $month, ?array $tenantIds = null): array
    {
        $period = app(ProfitLossSummaryCalculator::class)->period($year, $month);
        $markers = $this->profitLossDetailMarkers();
        if ($tenantIds !== null) {
            return app(TenantReportRunner::class)->runReports($tenantIds, fn () => $this->buildProfitLossDetail($period, $markers));
        }

        return $this->buildProfitLossDetail($period, $markers);
    }

    /**
     * Validated journal markers treated as profit transfers: service codes (journalByMapping
     * service_id) and source_type values, in addition to the opening-balance source types.
     */
    public function profitLossDetailMarkers(): array
    {
        $configured = config('accounting.profit_loss_detail', []);
        $markers = [];
        foreach (self::DEFAULT_PROFIT_LOSS_DETAIL_MARKERS as $key => $defaults) {
            // A missing key keeps the defaults (older published configs); an explicit [] disables it.
            $values = is_array($configured) ? (array_key_exists($key, $configured) ? $configured[$key] : $defaults) : null;
            if (! is_array($values) || array_filter($values, fn ($value) => ! is_string($value) || trim($value) === '') !== []) {
                throw ValidationException::withMessages([
                    'profit_loss_detail' => ['accounting.profit_loss_detail.'.$key.' must be a list of non-empty strings.'],
                ]);
            }
            $markers[$key] = array_values(array_unique($values));
        }

        return $markers;
    }

    protected function buildProfitLossDetail(array $period, array $markers): array
    {
        $types = $this->categories->allOrdered()->pluck('type', 'id');
        $normal = [];
        foreach ($this->accounts->allOrdered() as $account) {
            $type = $types->get($account->category_id);
            if (in_array($type, ['REVENUE', 'EXPENSE'], true)) {
                $normal[$account->id] = $type;
            }
        }
        // Services are master data: resolve ids through the repository, never by cross-connection join.
        $serviceIds = $markers['closing_service_codes'] === [] ? [] : app(ServiceRepository::class)->all()
            ->filter(fn ($service) => in_array($service->service_code, $markers['closing_service_codes'], true))
            ->modelKeys();
        $start = Carbon::create($period['year'], $period['month'], 1);
        $profit = function (?string $from, string $to, bool $inclusive) use ($normal, $serviceIds, $markers) {
            $total = 0.0;
            // Revenue (credit-normal) minus expense (debit-normal) = credit - debit across both.
            foreach ($this->profitLossDetailMovements(array_keys($normal), $from, $to, $inclusive, $serviceIds, $markers['closing_source_types']) as $movement) {
                $total += (float) $movement->total_credit - (float) $movement->total_debit;
            }

            return round($total, 2);
        };

        return [
            'prior_months_profit_loss' => $profit(null, $start->toDateString(), false),
            'current_month_profit_loss' => $profit($start->toDateString(), $start->copy()->endOfMonth()->toDateString(), true),
        ];
    }

    protected function profitLossDetailMovements(
        array $accountIds, ?string $from, string $to, bool $inclusive, array $serviceIds, array $closingSourceTypes
    ): Collection {
        if ($accountIds === []) {
            return collect();
        }

        [$query, $details, $journals, $grammar] = $this->movementQuery();
        $excludedSourceTypes = array_values(array_unique(array_merge(
            ['OPENING_BALANCE', AccountOpeningBalanceService::SOURCE_TYPE], $closingSourceTypes
        )));

        return $query
            ->whereIn($details.'.account_id', $accountIds)
            ->when($from !== null, fn ($query) => $query->whereDate($journals.'.trx_date', '>=', $from))
            ->whereDate($journals.'.trx_date', $inclusive ? '<=' : '<', $to)
            ->where($journals.'.status', JournalStatus::POSTED->value)
            ->where(fn ($query) => $query->whereNull($journals.'.source_type')
                ->orWhereNotIn($journals.'.source_type', $excludedSourceTypes))
            ->when($serviceIds !== [], fn ($query) => $query->where(fn ($inner) => $inner->whereNull($journals.'.service_id')
                ->orWhereNotIn($journals.'.service_id', $serviceIds)))
            ->groupBy($details.'.account_id')
            ->select($details.'.account_id')
            ->selectRaw('SUM('.$grammar->wrap($details.'.debit').') as total_debit')
            ->selectRaw('SUM('.$grammar->wrap($details.'.credit').') as total_credit')
            ->get()
            ->keyBy('account_id');
    }

    /**
     * Cash Flow Report
     * Menampilkan pergerakan kas.
     */
    public function cashFlow($year, $month)
    {
        $tree = $this->buildTreeWithBalances($year, $month);

        $operating = $this->pickRootNodesByType($tree, ['REVENUE', 'EXPENSE']);
        $investing = $this->pickNodesByCategoryCodes($tree, ['FIXED_ASSET', 'OTHER_ASSET']);
        $financing = $this->pickRootNodesByType($tree, ['LIABILITY', 'EQUITY']);

        return [
            'data' => [
                'operating' => $operating,
                'investing' => $investing,
                'financing' => $financing,
            ],
            'net_cash_flow' => $this->sumNodes($operating) + $this->sumNodes($investing) + $this->sumNodes($financing),
        ];
    }

    protected function buildProfitLossRangeReport(array $period): array
    {
        $tree = $this->buildTreeWithMovements($period);
        $revenue = $this->pickRootNodesByType($tree, ['REVENUE']);
        $expense = $this->pickRootNodesByType($tree, ['EXPENSE']);

        return [
            'data' => ['revenue' => $revenue, 'expense' => $expense],
            'net_income' => round($this->sumNodes($revenue) - $this->sumNodes($expense), 2),
        ];
    }

    protected function buildTreeWithMovements(array $period): Collection
    {
        $categories = $this->categories->allOrdered();
        $accounts = $this->accounts->allOrdered();
        $movements = $this->profitLossRangeMovements($accounts->pluck('id')->all(), $period);
        $types = $categories->pluck('type', 'id');
        $balances = $accounts->mapWithKeys(function ($account) use ($movements, $types) {
            $movement = $movements->get($account->id);
            $debit = (float) ($movement->total_debit ?? 0);
            $credit = (float) ($movement->total_credit ?? 0);
            $debitNormal = in_array($types->get($account->category_id), ['ASSET', 'EXPENSE'], true);

            // Adapt period movement to the existing tree balance attachment;
            // this value contains neither a snapshot nor an opening balance.
            return [$account->id => (object) [
                'ending_balance' => round($debitNormal ? $debit - $credit : $credit - $debit, 2),
            ]];
        });

        return $this->applyBalances($this->treeService->getTree($categories, $accounts), $balances);
    }

    protected function profitLossRangeMovements(array $accountIds, array $period): Collection
    {
        if ($accountIds === []) {
            return collect();
        }

        $start = Carbon::create($period['start_year'], $period['start_month'], 1)->toDateString();
        $end = Carbon::create($period['end_year'], $period['end_month'], 1)->endOfMonth()->toDateString();
        [$query, $details, $journals, $grammar] = $this->movementQuery();

        return $query
            ->whereIn($details.'.account_id', $accountIds)
            ->whereDate($journals.'.trx_date', '>=', $start)
            ->whereDate($journals.'.trx_date', '<=', $end)
            ->where($journals.'.status', JournalStatus::POSTED->value)
            ->where(function ($query) use ($journals) {
                $query->whereNull($journals.'.source_type')
                    ->orWhereNotIn($journals.'.source_type', ['OPENING_BALANCE', AccountOpeningBalanceService::SOURCE_TYPE]);
            })
            ->groupBy($details.'.account_id')
            ->select($details.'.account_id')
            ->selectRaw('SUM('.$grammar->wrap($details.'.debit').') as total_debit')
            ->selectRaw('SUM('.$grammar->wrap($details.'.credit').') as total_credit')
            ->get()
            ->keyBy('account_id');
    }

    protected function buildTreeWithBalances($year, $month): Collection
    {
        $accounts = $this->accounts->allOrdered();
        $balances = $this->central()->monthlyBalances((int) $year, (int) $month)
            ?? MonthlyBalance::on($this->transactionConnection())
                ->where('fiscal_year', $year)
                ->where('fiscal_month', $month)
                ->get()
                ->keyBy('account_id');

        $tree = $this->treeService->getTree(
            $this->categories->allOrdered(),
            $accounts
        );

        return $this->applyBalances(collect($tree), $balances);
    }

    protected function applyBalances(Collection $nodes, Collection $balancesByAccountId): Collection
    {
        return $nodes->map(function (array $node) use ($balancesByAccountId) {
            $children = $this->applyBalances(collect($node['children'] ?? []), $balancesByAccountId);

            $accounts = collect($node['accounts'] ?? [])->map(function (array $account) use ($balancesByAccountId, $node) {
                $endingBalance = (float) ($balancesByAccountId->get($account['id'])->ending_balance ?? 0);

                return $account + [
                    'balance' => $endingBalance,
                    'category_path' => $node['path'] ?? [],
                ];
            })->values();

            $node['children'] = $children->values()->all();
            $node['accounts'] = $accounts->all();
            $node['balance'] = $accounts->sum('balance') + $children->sum('balance');

            return $node;
        });
    }

    protected function pickRootNodesByType(Collection $tree, array $types): array
    {
        return $tree->filter(function (array $node) use ($types) {
            return in_array($node['type'], $types, true);
        })->values()->all();
    }

    protected function pickNodesByCategoryCodes(Collection $tree, array $codes): array
    {
        $matches = collect();

        $walk = function (array $node) use (&$walk, $codes, &$matches) {
            if (in_array($node['category_code'], $codes, true)) {
                $matches->push($node);
            }

            foreach ($node['children'] ?? [] as $child) {
                $walk($child);
            }
        };

        foreach ($tree as $node) {
            $walk($node);
        }

        return $matches->values()->all();
    }

    protected function sumByTypes(Collection $tree, array $types): float
    {
        return $this->sumNodes($this->pickRootNodesByType($tree, $types));
    }

    protected function sumNodes(array $nodes): float
    {
        return array_reduce($nodes, function ($carry, $node) {
            return $carry + (float) ($node['balance'] ?? 0);
        }, 0.0);
    }

    protected function transactionConnection(): ?string
    {
        return app(AccountingConnectionResolver::class)->resolveTransactionDataConnection();
    }

    /** Central tenant copies while a central-mode tenant report runs; master data is always read live. */
    protected function central(): CentralReportContext
    {
        return app(CentralReportContext::class);
    }

    /** Posted-movement base query: details joined to headers on the transaction or central connection. */
    protected function movementQuery(): array
    {
        if ($central = $this->central()->movementQuery()) {
            return $central;
        }

        $connection = DB::connection($this->transactionConnection());
        $details = $this->tables->table('journal_entry_details');
        $journals = $this->tables->table('journal_entries');

        return [
            $connection->table($details)->join($journals, $journals.'.id', '=', $details.'.journal_entry_id'),
            $details,
            $journals,
            $connection->getQueryGrammar(),
        ];
    }
}
