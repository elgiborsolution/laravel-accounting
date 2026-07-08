<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\JournalEntryDetail;
use ESolution\LaravelAccounting\Models\MonthlyBalance;
use ESolution\LaravelAccounting\Repositories\AccountCategoryRepository;
use ESolution\LaravelAccounting\Repositories\AccountRepository;
use ESolution\LaravelAccounting\Repositories\JournalRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportService
{
    public function __construct(
        protected AccountCategoryTreeService $treeService,
        protected AccountCategoryRepository $categories,
        protected AccountRepository $accounts,
        protected JournalRepository $journals
    ) {
    }

    /**
     * General Ledger Report
     * Menampilkan mutasi detail per GL account.
     */
    public function generalLedger($accountId, $startDate, $endDate)
    {
        $account = $this->accounts->findById($accountId);

        if (! $account) {
            abort(404);
        }

        $category = $this->categories->findById($account->category_id);

        $startYear = date('Y', strtotime($startDate));
        $startMonth = date('m', strtotime($startDate));

        $openingBalance = MonthlyBalance::where('account_id', $accountId)
            ->where('fiscal_year', $startYear)
            ->where('fiscal_month', (int) $startMonth)
            ->value('opening_balance') ?? 0;

        if (date('d', strtotime($startDate)) != '01') {
            $prefix = config('accounting.table_prefix', 'acc_');

            $prevTransactions = DB::table($prefix.'journal_entry_details')
                ->join($prefix.'journal_entries', $prefix.'journal_entries.id', '=', $prefix.'journal_entry_details.journal_entry_id')
                ->where($prefix.'journal_entry_details.account_id', $accountId)
                ->where($prefix.'journal_entries.trx_date', '>=', sprintf('%s-%s-01', $startYear, $startMonth))
                ->where($prefix.'journal_entries.trx_date', '<', $startDate)
                ->where($prefix.'journal_entries.status', 'posted')
                ->select(DB::raw("SUM({$prefix}journal_entry_details.debit) as total_debit, SUM({$prefix}journal_entry_details.credit) as total_credit"))
                ->first();

            if (in_array($category->type ?? 'ASSET', ['ASSET', 'EXPENSE'], true)) {
                $openingBalance += ($prevTransactions->total_debit ?? 0) - ($prevTransactions->total_credit ?? 0);
            } else {
                $openingBalance += ($prevTransactions->total_credit ?? 0) - ($prevTransactions->total_debit ?? 0);
            }
        }

        $details = $this->journals->getPostedDetailsByAccount($accountId, $startDate, $endDate);

        return [
            'account' => array_merge(
                $account->toArray(),
                [
                    'category_path' => $category ? $this->categories->buildLineage($category)->map(fn ($node) => $node->category_name)->all() : [],
                ]
            ),
            'opening_balance' => $openingBalance,
            'details' => $details,
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
    public function profitLoss($year, $month)
    {
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
     * Balance Sheet Report
     * Menampilkan posisi keuangan perusahaan.
     */
    public function balanceSheet($year, $month)
    {
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

    protected function buildTreeWithBalances($year, $month): Collection
    {
        $accounts = $this->accounts->allOrdered();
        $balances = MonthlyBalance::where('fiscal_year', $year)
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
}
