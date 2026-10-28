<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Enums\JournalStatus;
use ESolution\LaravelAccounting\Models\MonthlyBalance;
use ESolution\LaravelAccounting\Repositories\AccountCategoryRepository;
use ESolution\LaravelAccounting\Repositories\AccountRepository;
use ESolution\LaravelAccounting\Support\AccountingTableResolver;
use ESolution\LaravelAccounting\Support\AccountingConnectionResolver;
use ESolution\LaravelAccounting\Support\TenantReportRunner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportService
{
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
            return app(TenantReportRunner::class)->run($tenantIds, fn () => $this->profitLoss($year, $month));
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
            $reports = app(TenantReportRunner::class)->run(
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
            return app(TenantReportRunner::class)->run($tenantIds, fn () => $this->balanceSheet($year, $month));
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
        $connection = DB::connection($this->transactionConnection());
        $details = $this->tables->table('journal_entry_details');
        $journals = $this->tables->table('journal_entries');
        $grammar = $connection->getQueryGrammar();

        return $connection->table($details)
            ->join($journals, $journals.'.id', '=', $details.'.journal_entry_id')
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
        $balances = MonthlyBalance::on($this->transactionConnection())
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
}
