<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Enums\JournalStatus;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\FiscalPeriod;
use ESolution\LaravelAccounting\Models\MonthlyBalance;
use Exception;
use Illuminate\Support\Facades\DB;

class ClosingService
{
    /**
     * Perform monthly closing.
     */
    public function closeMonth($year, $month, $userId = null)
    {
        return DB::transaction(function () use ($year, $month, $userId) {
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
            $totals = DB::table(config('accounting.table_prefix', 'acc_').'journal_entries as j')
                ->join(config('accounting.table_prefix', 'acc_').'journal_entry_details as d', 'j.id', '=', 'd.journal_entry_id')
                ->whereYear('j.trx_date', $year)
                ->whereMonth('j.trx_date', $month)
                ->where('j.status', JournalStatus::POSTED)
                ->select(
                    DB::raw('SUM(d.debit) as total_debit'),
                    DB::raw('SUM(d.credit) as total_credit')
                )
                ->first();

            $totalDebit = $totals->total_debit ?? 0;
            $totalCredit = $totals->total_credit ?? 0;

            if (round($totalDebit, 2) !== round($totalCredit, 2)) {
                throw new Exception("Journals are not balanced for the period {$year}-{$month}. Total Debit: {$totalDebit}, Total Credit: {$totalCredit}");
            }

            // 3. Get all accounts with their category types
            $accounts = Account::with('category')->get();

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
            $activities = DB::table(config('accounting.table_prefix', 'acc_').'journal_entries as j')
                ->join(config('accounting.table_prefix', 'acc_').'journal_entry_details as d', 'j.id', '=', 'd.journal_entry_id')
                ->whereYear('j.trx_date', $year)
                ->whereMonth('j.trx_date', $month)
                ->where('j.status', JournalStatus::POSTED)
                ->groupBy('d.account_id')
                ->select(
                    'd.account_id',
                    DB::raw('SUM(d.debit) as debit'),
                    DB::raw('SUM(d.credit) as credit'),
                    DB::raw('COUNT(DISTINCT j.id) as journal_count')
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
                $type = $account->category->type ?? 'asset';
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
