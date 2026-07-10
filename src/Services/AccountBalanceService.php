<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Enums\JournalStatus;
use ESolution\LaravelAccounting\Repositories\AccountRepository;
use ESolution\LaravelAccounting\Models\MonthlyBalance;
use ESolution\LaravelAccounting\Repositories\AccountCategoryRepository;
use ESolution\LaravelAccounting\Repositories\FiscalPeriodRepository;
use ESolution\LaravelAccounting\Support\AccountingConnectionResolver;
use ESolution\LaravelAccounting\Support\AccountingTableResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccountBalanceService
{
    public function __construct(
        protected AccountCategoryRepository $categories,
        protected AccountRepository $accounts,
        protected FiscalPeriodRepository $periods,
        protected AccountingConnectionResolver $connections,
        protected AccountingTableResolver $tables
    ) {
    }

    public function getBalances(array $accountIds, ?int $year = null, ?int $month = null): Collection
    {
        $accountIds = collect($accountIds)
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();

        if ($accountIds->isEmpty()) {
            return collect();
        }

        [$year, $month] = $this->resolvePeriod($year, $month);
        $periodStart = Carbon::create($year, $month, 1)->startOfDay();
        $periodEnd = Carbon::create($year, $month, 1)->endOfMonth()->endOfDay();

        $accountRecords = $this->accounts->findManyByIds($accountIds);
        $categoryTypes = $this->categories->allOrdered()->pluck('type', 'id');

        $currentBalances = MonthlyBalance::query()
            ->whereIn('account_id', $accountIds)
            ->where('fiscal_year', $year)
            ->where('fiscal_month', $month)
            ->get()
            ->keyBy('account_id');

        $missingIds = $accountIds->reject(fn (string $accountId) => $currentBalances->has($accountId))->values();
        $previousBalances = $this->loadPreviousBalances($missingIds, $year, $month);
        $currentTotals = $this->loadJournalTotals($missingIds->all(), $periodStart, $periodEnd);

        $carryForwardTotals = collect();
        $latestJournalDate = $this->periods->resolveEarliestJournalDate();
        $carryStartFallback = $latestJournalDate ?: $periodStart;
        $carryEnd = $periodStart->copy()->subDay()->endOfDay();

        if ($missingIds->isNotEmpty() && $carryStartFallback->lte($carryEnd)) {
            $carryGroups = $this->groupMissingAccountsByPreviousPeriod($missingIds, $previousBalances);

            foreach ($carryGroups as $group) {
                $startDate = $group['start_date'];
                if ($startDate->lte($carryEnd)) {
                    $totals = $this->loadJournalTotals($group['account_ids'], $startDate, $carryEnd);
                    $carryForwardTotals = $carryForwardTotals->merge($totals);
                }
            }
        }

        return $accountIds->mapWithKeys(function (string $accountId) use (
            $accountRecords,
            $currentBalances,
            $previousBalances,
            $currentTotals,
            $carryForwardTotals,
            $categoryTypes,
            $periodStart,
            $periodEnd
        ) {
            $currentBalance = $currentBalances->get($accountId);

            if ($currentBalance) {
                return [$accountId => $this->formatMonthlyBalance($currentBalance)];
            }

            $categoryId = $accountRecords->get($accountId)?->category_id;
            $categoryType = $this->normalizeCategoryType($categoryTypes->get($categoryId, 'ASSET'));
            $isDebitNormal = in_array($categoryType, ['ASSET', 'EXPENSE'], true);

            $openingBalance = 0.0;
            $previousBalance = $previousBalances->get($accountId);

            if ($previousBalance) {
                $openingBalance = (float) $previousBalance->ending_balance;
            }

            $carryTotals = $carryForwardTotals->get($accountId);
            if ($carryTotals) {
                $openingBalance = $this->applyMovement(
                    $openingBalance,
                    (float) ($carryTotals->total_debit ?? 0),
                    (float) ($carryTotals->total_credit ?? 0),
                    $isDebitNormal
                );
            }

            $periodTotals = $currentTotals->get($accountId);
            $totalDebit = (float) ($periodTotals->total_debit ?? 0);
            $totalCredit = (float) ($periodTotals->total_credit ?? 0);

            $endingBalance = $this->applyMovement($openingBalance, $totalDebit, $totalCredit, $isDebitNormal);

            return [$accountId => [
                'opening_balance' => round($openingBalance, 2),
                'total_debit' => round($totalDebit, 2),
                'total_credit' => round($totalCredit, 2),
                'ending_balance' => round($endingBalance, 2),
            ]];
        });
    }

    protected function loadPreviousBalances(Collection $accountIds, int $year, int $month): Collection
    {
        if ($accountIds->isEmpty()) {
            return collect();
        }

        return MonthlyBalance::query()
            ->whereIn('account_id', $accountIds)
            ->where(function ($query) use ($year, $month) {
                $query->where('fiscal_year', '<', $year)
                    ->orWhere(function ($subQuery) use ($year, $month) {
                        $subQuery->where('fiscal_year', $year)
                            ->where('fiscal_month', '<', $month);
                    });
            })
            ->orderByDesc('fiscal_year')
            ->orderByDesc('fiscal_month')
            ->get()
            ->groupBy('account_id')
            ->map(fn (Collection $rows) => $rows->first());
    }

    protected function groupMissingAccountsByPreviousPeriod(Collection $accountIds, Collection $previousBalances): array
    {
        $groups = [];

        foreach ($accountIds as $accountId) {
            $previous = $previousBalances->get($accountId);

            if ($previous) {
                $key = $previous->fiscal_year.'-'.str_pad((string) $previous->fiscal_month, 2, '0', STR_PAD_LEFT);
                $startDate = Carbon::create($previous->fiscal_year, $previous->fiscal_month, 1)->endOfMonth()->addDay()->startOfDay();
            } else {
                $key = '__earliest__';
                $startDate = $this->periods->resolveEarliestJournalDate() ?: Carbon::now()->startOfDay();
            }

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'account_ids' => [],
                    'start_date' => $startDate,
                ];
            }

            $groups[$key]['account_ids'][] = $accountId;
            if ($startDate->lt($groups[$key]['start_date'])) {
                $groups[$key]['start_date'] = $startDate;
            }
        }

        return $groups;
    }

    protected function loadJournalTotals(array $accountIds, Carbon $startDate, Carbon $endDate): Collection
    {
        $accountIds = array_values(array_unique(array_filter($accountIds)));

        if ($accountIds === [] || $startDate->gt($endDate)) {
            return collect();
        }

        $tablePrefix = $this->tables->tablePrefix();
        $rawTable = $this->tables->rawTable('journal_entry_details', $this->transactionConnection());

        return DB::connection($this->transactionConnection())
            ->table($tablePrefix.'journal_entry_details')
            ->join($tablePrefix.'journal_entries', $tablePrefix.'journal_entries.id', '=', $tablePrefix.'journal_entry_details.journal_entry_id')
            ->whereIn($tablePrefix.'journal_entry_details.account_id', $accountIds)
            ->whereDate($tablePrefix.'journal_entries.trx_date', '>=', $startDate->toDateString())
            ->whereDate($tablePrefix.'journal_entries.trx_date', '<=', $endDate->toDateString())
            ->where($tablePrefix.'journal_entries.status', JournalStatus::POSTED->value)
            ->groupBy($tablePrefix.'journal_entry_details.account_id')
            ->select([
                $tablePrefix.'journal_entry_details.account_id',
                DB::raw('SUM('.$rawTable.'.debit) as total_debit'),
                DB::raw('SUM('.$rawTable.'.credit) as total_credit'),
            ])
            ->get()
            ->keyBy('account_id');
    }

    protected function formatMonthlyBalance(mixed $balance): array
    {
        return [
            'opening_balance' => (float) $balance->opening_balance,
            'total_debit' => (float) $balance->total_debit,
            'total_credit' => (float) $balance->total_credit,
            'ending_balance' => (float) $balance->ending_balance,
        ];
    }

    protected function applyMovement(float $balance, float $debit, float $credit, bool $isDebitNormal): float
    {
        return $isDebitNormal
            ? $balance + $debit - $credit
            : $balance + $credit - $debit;
    }

    protected function resolvePeriod(?int $year, ?int $month): array
    {
        $now = now();
        $year = $year && $year > 0 ? $year : (int) $now->year;
        $month = $month && $month >= 1 && $month <= 12 ? $month : (int) $now->month;

        return [$year, $month];
    }

    protected function normalizeCategoryType(?string $type): string
    {
        return strtoupper($type ?: 'ASSET');
    }

    protected function transactionConnection(): ?string
    {
        return $this->connections->resolveTransactionDataConnection();
    }
}
