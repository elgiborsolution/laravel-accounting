<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Enums\ReportType;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\JournalEntryDetail;
use ESolution\LaravelAccounting\Models\MonthlyBalance;
use ESolution\LaravelAccounting\Models\ReportMapping;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * General Ledger Report
     * Menampilkan mutasi detail per GL account.
     */
    public function generalLedger($accountId, $startDate, $endDate)
    {
        $account = Account::with('category')->findOrFail($accountId);

        // Get Opening Balance from MonthlyBalance
        $startYear = date('Y', strtotime($startDate));
        $startMonth = date('m', strtotime($startDate));

        $openingBalance = MonthlyBalance::where('account_id', $accountId)
            ->where('fiscal_year', $startYear)
            ->where('fiscal_month', (int) $startMonth)
            ->value('opening_balance') ?? 0;

        // Get transactions before startDate in the same month if startDate is not the 1st
        if (date('d', strtotime($startDate)) != '01') {
            $prevTransactions = JournalEntryDetail::where('account_id', $accountId)
                ->whereHas('header', function ($query) use ($startDate, $startYear, $startMonth) {
                    $query->where('trx_date', '>=', "$startYear-$startMonth-01")
                        ->where('trx_date', '<', $startDate)
                        ->where('status', 'posted');
                })
                ->select(DB::raw('SUM(debit) as total_debit, SUM(credit) as total_credit'))
                ->first();

            if ($account->category->type === 'asset' || $account->category->type === 'expense') {
                $openingBalance += ($prevTransactions->total_debit ?? 0) - ($prevTransactions->total_credit ?? 0);
            } else {
                $openingBalance += ($prevTransactions->total_credit ?? 0) - ($prevTransactions->total_debit ?? 0);
            }
        }

        $details = JournalEntryDetail::with(['header'])
            ->where('account_id', $accountId)
            ->whereHas('header', function ($query) use ($startDate, $endDate) {
                $query->whereBetween('trx_date', [$startDate, $endDate])
                    ->where('status', 'posted');
            })
            ->get();

        return [
            'account' => $account,
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
        return MonthlyBalance::with(['account.category'])
            ->where('fiscal_year', $year)
            ->where('fiscal_month', $month)
            ->get();
    }

    /**
     * Profit & Loss Report
     * Menghitung laba/rugi perusahaan.
     */
    public function profitLoss($year, $month)
    {
        $mappings = ReportMapping::with(['account'])
            ->where('report_type', ReportType::PROFIT_LOSS)
            ->where('is_active', true)
            ->orderBy('sequence_no')
            ->get();

        $balances = MonthlyBalance::where('fiscal_year', $year)
            ->where('fiscal_month', $month)
            ->get()
            ->keyBy('account_id');

        $reportData = $mappings->map(function ($mapping) use ($balances) {
            $balance = $balances->get($mapping->account_id);

            return [
                'group' => $mapping->report_group,
                'subgroup' => $mapping->report_subgroup,
                'account_code' => $mapping->account->code,
                'account_name' => $mapping->account->name,
                'balance' => $balance ? $balance->ending_balance : 0,
            ];
        });

        $totalRevenue = $reportData->where('group', 'REVENUE')->sum('balance');
        $totalExpense = $reportData->whereIn('group', ['COGS', 'OPERATING_EXPENSE', 'OTHER_EXPENSE'])->sum('balance');

        return [
            'data' => $reportData,
            'net_income' => $totalRevenue - $totalExpense,
        ];
    }

    /**
     * Balance Sheet Report
     * Menampilkan posisi keuangan perusahaan.
     */
    public function balanceSheet($year, $month)
    {
        $mappings = ReportMapping::with(['account'])
            ->where('report_type', ReportType::BALANCE_SHEET)
            ->where('is_active', true)
            ->orderBy('sequence_no')
            ->get();

        $balances = MonthlyBalance::where('fiscal_year', $year)
            ->where('fiscal_month', $month)
            ->get()
            ->keyBy('account_id');

        $reportData = $mappings->map(function ($mapping) use ($balances) {
            $balance = $balances->get($mapping->account_id);

            return [
                'group' => $mapping->report_group,
                'subgroup' => $mapping->report_subgroup,
                'account_code' => $mapping->account->code,
                'account_name' => $mapping->account->name,
                'balance' => $balance ? $balance->ending_balance : 0,
            ];
        });

        return [
            'data' => $reportData,
            'total_assets' => $reportData->where('group', 'ASSET')->sum('balance'),
            'total_liabilities' => $reportData->where('group', 'LIABILITY')->sum('balance'),
            'total_equity' => $reportData->where('group', 'EQUITY')->sum('balance'),
        ];
    }

    /**
     * Cash Flow Report
     * Menampilkan pergerakan kas.
     */
    public function cashFlow($year, $month)
    {
        // Implementation for Cash Flow
        // This is more complex as it usually involves identifying cash accounts and their counterparts
        // For now, let's follow the basic logic of report mappings for CF

        $mappings = ReportMapping::with(['account'])
            ->where('report_type', ReportType::CASH_FLOW)
            ->where('is_active', true)
            ->orderBy('sequence_no')
            ->get();

        $startDate = "$year-$month-01";
        $endDate = date('Y-m-t', strtotime($startDate));

        $cashFlowData = $mappings->map(function ($mapping) use ($startDate, $endDate) {
            $sum = JournalEntryDetail::where('account_id', $mapping->account_id)
                ->whereHas('header', function ($query) use ($startDate, $endDate) {
                    $query->whereBetween('trx_date', [$startDate, $endDate])
                        ->where('status', 'posted');
                })
                ->select(DB::raw('SUM(debit) - SUM(credit) as net_change'))
                ->first();

            return [
                'group' => $mapping->report_group,
                'subgroup' => $mapping->report_subgroup,
                'account_code' => $mapping->account->code,
                'account_name' => $mapping->account->name,
                'net_change' => $sum->net_change ?? 0,
            ];
        });

        return [
            'data' => $cashFlowData,
            'net_cash_flow' => $cashFlowData->sum('net_change'),
        ];
    }
}
