<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Enums\JournalStatus;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\FiscalPeriod;
use ESolution\LaravelAccounting\Models\MonthlyBalance;
use ESolution\LaravelAccounting\Repositories\AccountCategoryRepository;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ClosingService
{
    public function __construct(protected AccountCategoryRepository $categories) {}

    protected function journalTable(): string
    {
        return config('accounting.table_prefix', 'acc_').'journal_entries';
    }

    protected function journalDetailTable(): string
    {
        return config('accounting.table_prefix', 'acc_').'journal_entry_details';
    }

    protected function wrapTable(string $table): string
    {
        return DB::connection()->getQueryGrammar()->wrapTable($table);
    }

    /**
     * Perform monthly closing.
     */
    public function closeMonth($year, $month, $userId = null)
    {
        return DB::transaction(function () use ($year, $month, $userId) {
            app(FiscalPeriodService::class)->ensureForDate(Carbon::create($year, $month, 1));

            // 1. Validate fiscal period exists
            $period = FiscalPeriod::where('year', $year)
                ->where('month', $month)
                ->first();

            if (! $period) {
                throw new Exception("Fiscal period {$year}-{$month} not found");
            }

            if ($period->is_closed) {
                throw new Exception("Fiscal period {$year}-{$month} is already closed");
            }

            // 2. Validate journals are balanced
            $journalTable = $this->journalTable();
            $detailTable = $this->journalDetailTable();
            $journalTableWrapped = $this->wrapTable($journalTable);
            $detailTableWrapped = $this->wrapTable($detailTable);

            $totals = DB::table($journalTable)
                ->join($detailTable, "{$journalTable}.id", '=', "{$detailTable}.journal_entry_id")
                ->whereYear("{$journalTable}.trx_date", $year)
                ->whereMonth("{$journalTable}.trx_date", $month)
                ->where("{$journalTable}.status", JournalStatus::POSTED)
                ->select(
                    DB::raw("SUM({$detailTableWrapped}.debit) as total_debit"),
                    DB::raw("SUM({$detailTableWrapped}.credit) as total_credit")
                )
                ->first();

            $totalDebit = $totals->total_debit ?? 0;
            $totalCredit = $totals->total_credit ?? 0;

            if (round($totalDebit, 2) !== round($totalCredit, 2)) {
                throw new Exception("Journals are not balanced for the period {$year}-{$month}. Total Debit: {$totalDebit}, Total Credit: {$totalCredit}");
            }

            // 3. Get all accounts with their category types
            $accounts = Account::query()->get();
            $categoryTypes = $this->categories->allOrdered()->pluck('type', 'id');

            // 4. Get opening balances from previous month
            $prevMonth = $month - 1;
            $prevYear = $year;
            if ($prevMonth == 0) {
                $prevMonth = 12;
                $prevYear = $year - 1;
            }

            $prevBalances = MonthlyBalance::where('fiscal_year', $prevYear)
                ->where('fiscal_month', $prevMonth)
                ->pluck('ending_balance', 'account_id');

            // 5. Get current month activity grouped by account
            $activities = DB::table($journalTable)
                ->join($detailTable, "{$journalTable}.id", '=', "{$detailTable}.journal_entry_id")
                ->whereYear("{$journalTable}.trx_date", $year)
                ->whereMonth("{$journalTable}.trx_date", $month)
                ->where("{$journalTable}.status", JournalStatus::POSTED)
                ->groupBy("{$detailTable}.account_id")
                ->select(
                    "{$detailTable}.account_id",
                    DB::raw("SUM({$detailTableWrapped}.debit) as debit"),
                    DB::raw("SUM({$detailTableWrapped}.credit) as credit"),
                    DB::raw("COUNT(DISTINCT {$journalTableWrapped}.id) as journal_count")
                )
                ->get()
                ->keyBy('account_id');

            // 6. Calculate and Upsert Monthly Balances
            foreach ($accounts as $account) {
                $opening = $prevBalances[$account->id] ?? 0;
                $activity = $activities[$account->id] ?? (object) ['debit' => 0, 'credit' => 0, 'journal_count' => 0];

                $debit = $activity->debit ?? 0;
                $credit = $activity->credit ?? 0;

                // Determine normal balance from category type
                $type = $categoryTypes->get($account->category_id, 'asset');
                $isDebitNormal = in_array($type, ['asset', 'expense']);

                if ($isDebitNormal) {
                    $ending = $opening + $debit - $credit;
                } else {
                    $ending = $opening + $credit - $debit;
                }

                MonthlyBalance::updateOrCreate(
                    [
                        'fiscal_year' => $year,
                        'fiscal_month' => $month,
                        'account_id' => $account->id,
                    ],
                    [
                        'opening_balance' => $opening,
                        'total_debit' => $debit,
                        'total_credit' => $credit,
                        'ending_balance' => $ending,
                        'journal_count' => $activity->journal_count ?? 0,
                        'closed_at' => now(),
                        'closed_by' => $userId,
                    ]
                );
            }

            // 7. Mark period as closed
            $period->update([
                'is_closed' => true,
                'closed_at' => now(),
                'closed_by' => $userId,
            ]);

            return $period;
        });
    }

    /**
     * Close all open fiscal periods up to the current month.
     */
    public function closeThroughCurrentMonth($userId = null)
    {
        return DB::transaction(function () use ($userId) {
            $periods = app(FiscalPeriodService::class)->ensureThroughCurrentMonth();

            $periods = $periods->filter(fn (FiscalPeriod $period) => ! $period->is_closed);

            if ($periods->isEmpty()) {
                return collect();
            }

            $closedPeriods = collect();

            foreach ($periods as $period) {
                $closedPeriods->push($this->closeMonth($period->year, $period->month, $userId));
            }

            return $closedPeriods;
        });
    }

    /**
     * Alias for closing all open periods up to the current month.
     */
    public function closeUntilCurrentMonth($userId = null)
    {
        return $this->closeThroughCurrentMonth($userId);
    }

    /**
     * Reopen a closed month.
     */
    public function reopenMonth($year, $month, $userId = null)
    {
        return DB::transaction(function () use ($year, $month) {
            $period = FiscalPeriod::where('year', $year)
                ->where('month', $month)
                ->first();

            if (! $period) {
                throw new Exception("Fiscal period {$year}-{$month} not found");
            }

            if (! $period->is_closed) {
                throw new Exception("Fiscal period {$year}-{$month} is not closed");
            }

            // Check if any subsequent months are closed
            $subsequentClosed = FiscalPeriod::where(function ($query) use ($year, $month) {
                $query->where('year', '>', $year)
                    ->orWhere(function ($q) use ($year, $month) {
                        $q->where('year', $year)->where('month', '>', $month);
                    });
            })->where('is_closed', true)->exists();

            if ($subsequentClosed) {
                throw new Exception('Cannot reopen this month because subsequent months are already closed. Please reopen them first.');
            }

            $period->update([
                'is_closed' => false,
                'closed_at' => null,
                'closed_by' => null,
            ]);

            return $period;
        });
    }
}
