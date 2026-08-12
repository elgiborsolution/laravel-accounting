<?php

namespace ESolution\LaravelAccounting\Tests\Feature;

use ESolution\LaravelAccounting\Enums\JournalStatus;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use ESolution\LaravelAccounting\Models\JournalEntry;
use ESolution\LaravelAccounting\Models\JournalEntryDetail;
use ESolution\LaravelAccounting\Models\MonthlyBalance;
use ESolution\LaravelAccounting\Services\GeneralLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeneralLedgerControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_monthly_ledger_summary_without_journal_details(): void
    {
        $account = $this->createCashAccount();

        MonthlyBalance::create([
            'fiscal_year' => 2026,
            'fiscal_month' => 7,
            'account_id' => $account->id,
            'opening_balance' => 1000,
            'total_debit' => 125,
            'total_credit' => 25,
            'ending_balance' => 1100,
            'journal_count' => 2,
        ]);

        $this->createMovement($account, 'JV-2026-0001', '2026-07-10', 125, 0, 'Cash receipt');
        $this->createMovement($account, 'JV-2026-0002', '2026-07-20', 0, 25, 'Cash payment');
        $this->createMovement($account, 'JV-2026-0003', '2026-08-01', 500, 0, 'Next month');
        $this->createMovement($account, 'JV-2026-0004', '2026-07-25', 300, 0, 'Draft movement', JournalStatus::DRAFT);

        $response = $this->getJson("/api/accounting/reports/general-ledger?account_id={$account->id}&year=2026&month=7");

        $response->assertOk()
            ->assertJsonPath('data.account.id', $account->id)
            ->assertJsonPath('data.period.year', 2026)
            ->assertJsonPath('data.period.month', 7)
            ->assertJsonPath('data.period.start_date', '2026-07-01')
            ->assertJsonPath('data.period.end_date', '2026-07-31')
            ->assertJsonPath('data.opening_balance', 1000)
            ->assertJsonPath('data.total_debit', 125)
            ->assertJsonPath('data.total_credit', 25)
            ->assertJsonPath('data.ending_balance', 1100)
            ->assertJsonMissingPath('data.details');
    }

    public function test_it_requires_a_complete_monthly_period_when_dates_are_not_supplied(): void
    {
        $account = $this->createCashAccount();

        $this->getJson("/api/accounting/reports/general-ledger?account_id={$account->id}&year=2026")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['month']);
    }

    public function test_detail_api_returns_the_service_paginator_without_a_wrapper_and_keeps_running_balance_across_pages(): void
    {
        $account = $this->createCashAccount();

        MonthlyBalance::create([
            'fiscal_year' => 2026,
            'fiscal_month' => 7,
            'account_id' => $account->id,
            'opening_balance' => 1000,
            'total_debit' => 800,
            'total_credit' => 300,
            'ending_balance' => 1500,
            'journal_count' => 3,
        ]);

        $this->createMovement($account, 'JV-2026-0101', '2026-07-01', 100, 0, 'First movement');
        $this->createMovement($account, 'JV-2026-0102', '2026-07-02', 700, 0, 'Second movement');
        $this->createMovement($account, 'JV-2026-0103', '2026-07-03', 0, 300, 'Third movement');

        $url = "/api/accounting/reports/general-ledger/details?account_id={$account->id}&year=2026&month=7&page=2&per_page=1";
        $response = $this->getJson($url);

        $response->assertOk()
            ->assertJsonPath('current_page', 2)
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('total', 3)
            ->assertJsonPath('opening_balance', 1000)
            ->assertJsonPath('total_debit', 800)
            ->assertJsonPath('total_credit', 300)
            ->assertJsonPath('ending_balance', 1500)
            ->assertJsonPath('account.id', $account->id)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.journal_number', 'JV-2026-0102')
            ->assertJsonPath('data.0.ending_balance', 1800)
            ->assertJsonMissingPath('status')
            ->assertJsonMissingPath('message');

        $serviceResult = app(GeneralLedgerService::class)->getLedgerDetails($account->id, 2026, 7, 2, 1);

        $this->assertEquals($serviceResult->toArray(), $response->json());
    }

    private function createCashAccount(): Account
    {
        $category = AccountCategory::create([
            'type' => 'ASSET',
            'category_code' => 'CURRENT_ASSETS',
            'category_name' => 'Current Assets',
            'report_type' => 'BS',
            'sequence_no' => 1,
            'status' => true,
        ]);

        return Account::create([
            'category_id' => $category->id,
            'code' => '1001',
            'name' => 'Cash',
            'is_postable' => true,
            'status' => true,
        ]);
    }

    private function createMovement(
        Account $account,
        string $journalNo,
        string $date,
        float $debit,
        float $credit,
        string $description,
        JournalStatus $status = JournalStatus::POSTED
    ): void {
        $journal = JournalEntry::create([
            'journal_no' => $journalNo,
            'trx_date' => $date,
            'description' => 'Journal '.$journalNo,
            'status' => $status,
        ]);

        JournalEntryDetail::create([
            'journal_entry_id' => $journal->id,
            'account_id' => $account->id,
            'debit' => $debit,
            'credit' => $credit,
            'description' => $description,
        ]);
    }
}
