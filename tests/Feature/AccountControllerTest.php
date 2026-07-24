<?php

namespace ESolution\LaravelAccounting\Tests\Feature;

use ESolution\LaravelAccounting\Enums\JournalStatus;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use ESolution\LaravelAccounting\Models\FiscalPeriod;
use ESolution\LaravelAccounting\Models\JournalEntry;
use ESolution\LaravelAccounting\Models\JournalEntryDetail;
use ESolution\LaravelAccounting\Models\MonthlyBalance;
use ESolution\LaravelAccounting\Services\AccountBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AccountControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        // Seed categories
        $this->artisan('db:seed', ['--class' => 'ESolution\LaravelAccounting\Database\Seeders\AccountCategorySeeder']);
    }

    public function test_can_list_accounts()
    {
        $category = AccountCategory::where('category_code', 'CASH_CASH_EQUIVALENT')->first();
        Account::factory()->create([
            'category_id' => $category->id,
            'code' => '1001',
            'name' => 'Cash',
            'description' => 'Kas operasional perusahaan.',
        ]);

        $response = $this->getJson('/api/accounting/accounts');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.code', '1001')
            ->assertJsonPath('data.0.description', 'Kas operasional perusahaan.');
        $response->assertJsonMissingPath('data.0.category');
        $response->assertJsonMissingPath('data.0.tree_category');
        $response->assertJsonMissingPath('data.0.balance');
    }

    public function test_can_list_accounts_with_balance_from_monthly_balance(): void
    {
        $category = AccountCategory::where('category_code', 'CASH_CASH_EQUIVALENT')->first();
        $account = Account::factory()->create(['category_id' => $category->id, 'code' => '1005', 'name' => 'Balance Account']);

        MonthlyBalance::create([
            'fiscal_year' => 2026,
            'fiscal_month' => 7,
            'account_id' => $account->id,
            'opening_balance' => 1000000,
            'total_debit' => 500000,
            'total_credit' => 250000,
            'ending_balance' => 1250000,
            'journal_count' => 3,
            'closed_at' => now(),
            'closed_by' => null,
        ]);

        $this->assertSame(1, MonthlyBalance::query()->where('account_id', $account->id)->where('fiscal_year', 2026)->where('fiscal_month', 7)->count());

        $balances = app(AccountBalanceService::class)->getBalances([$account->id], 2026, 7);
        $this->assertSame(1000000.0, (float) data_get($balances->get($account->id), 'opening_balance'));

        $response = $this->getJson('/api/accounting/accounts?with=balance&year=2026&month=7');

        $response->assertOk();

        $item = collect($response->json('data'))->firstWhere('id', $account->id);

        $this->assertSame(1000000.0, (float) data_get($item, 'balance.opening_balance'));
        $this->assertSame(500000.0, (float) data_get($item, 'balance.total_debit'));
        $this->assertSame(250000.0, (float) data_get($item, 'balance.total_credit'));
        $this->assertSame(1250000.0, (float) data_get($item, 'balance.ending_balance'));
        $this->assertArrayNotHasKey('category', $item);
    }

    public function test_can_list_accounts_with_balance_fallback_to_journals_when_monthly_balance_missing(): void
    {
        $category = AccountCategory::where('category_code', 'CASH_CASH_EQUIVALENT')->first();
        $account = Account::factory()->create(['category_id' => $category->id, 'code' => '1006', 'name' => 'Fallback Account']);

        MonthlyBalance::create([
            'fiscal_year' => 2026,
            'fiscal_month' => 5,
            'account_id' => $account->id,
            'opening_balance' => 0,
            'total_debit' => 100000,
            'total_credit' => 0,
            'ending_balance' => 100000,
            'journal_count' => 1,
            'closed_at' => now(),
            'closed_by' => null,
        ]);

        $juneJournal = JournalEntry::create([
            'journal_no' => 'JV/2026/06/0001',
            'trx_date' => '2026-06-15',
            'description' => 'June carry forward',
            'status' => JournalStatus::POSTED,
            'posted_at' => now(),
        ]);

        JournalEntryDetail::create([
            'journal_entry_id' => $juneJournal->id,
            'account_id' => $account->id,
            'debit' => 20000,
            'credit' => 0,
            'description' => 'June debit',
        ]);

        $julyJournal = JournalEntry::create([
            'journal_no' => 'JV/2026/07/0001',
            'trx_date' => '2026-07-10',
            'description' => 'July movement',
            'status' => JournalStatus::POSTED,
            'posted_at' => now(),
        ]);

        JournalEntryDetail::create([
            'journal_entry_id' => $julyJournal->id,
            'account_id' => $account->id,
            'debit' => 30000,
            'credit' => 0,
            'description' => 'July debit',
        ]);

        $response = $this->getJson('/api/accounting/accounts?with=balance&year=2026&month=7');

        $response->assertOk();

        $item = collect($response->json('data'))->firstWhere('id', $account->id);

        $this->assertSame(120000.0, (float) data_get($item, 'balance.opening_balance'));
        $this->assertSame(30000.0, (float) data_get($item, 'balance.total_debit'));
        $this->assertSame(0.0, (float) data_get($item, 'balance.total_credit'));
        $this->assertSame(150000.0, (float) data_get($item, 'balance.ending_balance'));
    }

    public function test_can_list_accounts_with_category_and_balance(): void
    {
        $category = AccountCategory::where('category_code', 'CASH_CASH_EQUIVALENT')->first();
        $account = Account::factory()->create(['category_id' => $category->id, 'code' => '1007', 'name' => 'Combo Account']);

        MonthlyBalance::create([
            'fiscal_year' => 2026,
            'fiscal_month' => 7,
            'account_id' => $account->id,
            'opening_balance' => 1,
            'total_debit' => 2,
            'total_credit' => 0,
            'ending_balance' => 3,
            'journal_count' => 1,
            'closed_at' => now(),
            'closed_by' => null,
        ]);

        $response = $this->getJson('/api/accounting/accounts?with=category,balance&year=2026&month=7');

        $response->assertOk();

        $item = collect($response->json('data'))->firstWhere('id', $account->id);

        $this->assertSame($category->id, data_get($item, 'category.id'));
        $this->assertSame(3.0, (float) data_get($item, 'balance.ending_balance'));
    }

    public function test_can_list_accounts_with_tree_category_and_balance(): void
    {
        $root = AccountCategory::where('category_code', 'ASSET')->firstOrFail();
        $parent = AccountCategory::where('category_code', 'CURRENT_ASSET')->firstOrFail();
        $leaf = AccountCategory::where('category_code', 'CASH_CASH_EQUIVALENT')->firstOrFail();

        $account = Account::factory()->create(['category_id' => $leaf->id, 'code' => '1008', 'name' => 'Tree Combo Account']);

        MonthlyBalance::create([
            'fiscal_year' => 2026,
            'fiscal_month' => 7,
            'account_id' => $account->id,
            'opening_balance' => 10,
            'total_debit' => 20,
            'total_credit' => 0,
            'ending_balance' => 30,
            'journal_count' => 1,
            'closed_at' => now(),
            'closed_by' => null,
        ]);

        $response = $this->getJson('/api/accounting/accounts?with=tree_category,balance&year=2026&month=7');

        $response->assertOk();

        $item = collect($response->json('data'))->firstWhere('id', $account->id);

        $this->assertSame($root->id, data_get($item, 'tree_category.0.id'));
        $this->assertSame($parent->id, data_get($item, 'tree_category.1.id'));
        $this->assertSame($leaf->id, data_get($item, 'tree_category.2.id'));
        $this->assertSame(30.0, (float) data_get($item, 'balance.ending_balance'));
    }

    public function test_can_list_accounts_with_category(): void
    {
        $category = AccountCategory::where('category_code', 'CASH_CASH_EQUIVALENT')->first();
        $account = Account::factory()->create(['category_id' => $category->id, 'code' => '1003', 'name' => 'Receivable']);

        $response = $this->getJson('/api/accounting/accounts?with=category');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $account->id)
            ->assertJsonPath('data.0.category.id', $category->id)
            ->assertJsonPath('data.0.category.category_code', 'CASH_CASH_EQUIVALENT');
    }

    public function test_can_list_accounts_with_tree_category(): void
    {
        $root = AccountCategory::where('category_code', 'ASSET')->firstOrFail();
        $parent = AccountCategory::where('category_code', 'CURRENT_ASSET')->firstOrFail();
        $leaf = AccountCategory::where('category_code', 'CASH_CASH_EQUIVALENT')->firstOrFail();

        $account = Account::factory()->create(['category_id' => $leaf->id, 'code' => '1004', 'name' => 'Cash Tree']);

        $response = $this->getJson('/api/accounting/accounts?with=tree_category');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $account->id)
            ->assertJsonPath('data.0.tree_category.0.id', $root->id)
            ->assertJsonPath('data.0.tree_category.1.id', $parent->id)
            ->assertJsonPath('data.0.tree_category.2.id', $leaf->id);
    }

    public function test_can_create_account()
    {
        $category = AccountCategory::where('category_code', 'CASH_CASH_EQUIVALENT')->first();

        $data = [
            'category_id' => $category->id,
            'code' => '1002',
            'name' => 'Bank BCA',
            'description' => 'Rekening utama untuk transaksi penjualan.',
            'status' => true,
        ];

        $response = $this->postJson('/api/accounting/accounts', $data);

        $response->assertStatus(201)
            ->assertJsonPath('data.code', '1002')
            ->assertJsonPath('data.description', 'Rekening utama untuk transaksi penjualan.');

        $this->assertDatabaseHas('acc_accounts', [
            'code' => '1002',
            'description' => 'Rekening utama untuk transaksi penjualan.',
        ]);
    }

    public function test_can_create_account_with_opening_balance(): void
    {
        $category = AccountCategory::where('category_code', 'CASH_CASH_EQUIVALENT')->firstOrFail();
        $this->createOpeningBalanceEquityAccount();

        $response = $this->postJson('/api/accounting/accounts', [
            'category_id' => $category->id,
            'code' => '1013',
            'name' => 'Cash Opening',
            'description' => 'Opening balance account.',
            'is_postable' => true,
            'status' => true,
            'opening_balance' => 1000000,
            'opening_balance_date' => '2026-01-01',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.code', '1013');

        $accountId = $response->json('data.id');
        $journal = JournalEntry::query()
            ->where('source_type', 'ACCOUNT_OPENING_BALANCE')
            ->where('source_id', $accountId)
            ->first();

        $this->assertNotNull($journal);
        $this->assertSame('OPENING-1013', $journal->reference_no);
        $this->assertSame('Opening Balance - Cash Opening', $journal->description);
        $this->assertSame(1000000.0, (float) $journal->amount);
        $this->assertSame(JournalStatus::POSTED, $journal->status);

        $details = JournalEntryDetail::query()
            ->where('journal_entry_id', $journal->id)
            ->orderBy('debit', 'desc')
            ->get();

        $this->assertCount(2, $details);
        $this->assertSame($accountId, $details[0]->account_id);
        $this->assertSame(1000000.0, (float) $details[0]->debit);
        $this->assertSame(0.0, (float) $details[0]->credit);
        $this->assertSame(0.0, (float) $details[1]->debit);
        $this->assertSame(1000000.0, (float) $details[1]->credit);
    }

    public function test_can_show_account()
    {
        $category = AccountCategory::where('category_code', 'CASH_CASH_EQUIVALENT')->first();
        $account = Account::factory()->create([
            'category_id' => $category->id,
            'description' => 'Kas operasional perusahaan.',
        ]);

        $response = $this->getJson("/api/accounting/accounts/{$account->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $account->id)
            ->assertJsonPath('data.description', 'Kas operasional perusahaan.')
            ->assertJsonPath('data.balance.opening_balance', 0)
            ->assertJsonPath('data.balance.total_debit', 0)
            ->assertJsonPath('data.balance.total_credit', 0)
            ->assertJsonPath('data.balance.ending_balance', 0);
    }

    public function test_can_show_account_with_balance(): void
    {
        $category = AccountCategory::where('category_code', 'CASH_CASH_EQUIVALENT')->first();
        $account = Account::factory()->create(['category_id' => $category->id]);

        MonthlyBalance::create([
            'fiscal_year' => 2026,
            'fiscal_month' => 7,
            'account_id' => $account->id,
            'opening_balance' => 100,
            'total_debit' => 50,
            'total_credit' => 10,
            'ending_balance' => 140,
            'journal_count' => 1,
            'closed_at' => now(),
            'closed_by' => null,
        ]);

        $response = $this->getJson("/api/accounting/accounts/{$account->id}?year=2026&month=7");

        $response->assertOk()
            ->assertJsonPath('data.id', $account->id)
            ->assertJsonPath('data.balance.opening_balance', 100)
            ->assertJsonPath('data.balance.total_debit', 50)
            ->assertJsonPath('data.balance.total_credit', 10)
            ->assertJsonPath('data.balance.ending_balance', 140);
    }

    public function test_can_update_account()
    {
        $category = AccountCategory::where('category_code', 'CASH_CASH_EQUIVALENT')->first();
        $account = Account::factory()->create(['category_id' => $category->id, 'name' => 'Old Name']);

        $response = $this->putJson("/api/accounting/accounts/{$account->id}", [
            'name' => 'New Name',
            'description' => 'Digunakan sebagai account penampung pembayaran customer.',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.description', 'Digunakan sebagai account penampung pembayaran customer.');
    }

    public function test_can_update_account_with_first_opening_balance(): void
    {
        $category = AccountCategory::where('category_code', 'CASH_CASH_EQUIVALENT')->firstOrFail();
        $this->createOpeningBalanceEquityAccount();

        $account = Account::factory()->create([
            'category_id' => $category->id,
            'code' => '1014',
            'name' => 'Cash Before Opening',
            'is_postable' => true,
            'status' => true,
        ]);

        $response = $this->putJson("/api/accounting/accounts/{$account->id}", [
            'name' => 'Cash After Opening',
            'opening_balance' => 250000,
            'opening_balance_date' => '2026-01-01',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Cash After Opening');

        $journal = JournalEntry::query()
            ->where('source_type', 'ACCOUNT_OPENING_BALANCE')
            ->where('source_id', $account->id)
            ->first();

        $this->assertNotNull($journal);
        $this->assertSame('OPENING-1014', $journal->reference_no);
        $this->assertSame('Opening Balance - Cash After Opening', $journal->description);
        $this->assertSame(250000.0, (float) $journal->amount);
    }

    public function test_prevents_setting_opening_balance_more_than_once(): void
    {
        $category = AccountCategory::where('category_code', 'CASH_CASH_EQUIVALENT')->firstOrFail();
        $equity = $this->createOpeningBalanceEquityAccount();

        $account = Account::factory()->create([
            'category_id' => $category->id,
            'code' => '1015',
            'name' => 'Cash Existing Opening',
            'is_postable' => true,
            'status' => true,
        ]);

        $journal = JournalEntry::create([
            'journal_no' => 'JV/2026/01/0001',
            'trx_date' => '2026-01-01',
            'source_type' => 'ACCOUNT_OPENING_BALANCE',
            'source_id' => $account->id,
            'reference_no' => 'OPENING-1015',
            'description' => 'Opening Balance - Cash Existing Opening',
            'amount' => 1000000,
            'status' => JournalStatus::POSTED,
            'posted_at' => now(),
        ]);

        JournalEntryDetail::create([
            'journal_entry_id' => $journal->id,
            'account_id' => $account->id,
            'debit' => 1000000,
            'credit' => 0,
            'description' => 'Opening Balance - Cash Existing Opening',
        ]);

        JournalEntryDetail::create([
            'journal_entry_id' => $journal->id,
            'account_id' => $equity->id,
            'debit' => 0,
            'credit' => 1000000,
            'description' => 'Opening Balance - Cash Existing Opening',
        ]);

        $response = $this->putJson("/api/accounting/accounts/{$account->id}", [
            'opening_balance' => 2000000,
            'opening_balance_date' => '2026-01-02',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['opening_balance']);

        $this->assertSame(
            'Opening balance has already been set for this account.',
            $response->json('errors.opening_balance.0')
        );
    }

    public function test_allows_updating_other_fields_after_opening_balance_exists(): void
    {
        $category = AccountCategory::where('category_code', 'CASH_CASH_EQUIVALENT')->firstOrFail();
        $equity = $this->createOpeningBalanceEquityAccount();

        $account = Account::factory()->create([
            'category_id' => $category->id,
            'code' => '1016',
            'name' => 'Cash Original Name',
            'is_postable' => true,
            'status' => true,
        ]);

        $journal = JournalEntry::create([
            'journal_no' => 'JV/2026/01/0002',
            'trx_date' => '2026-01-01',
            'source_type' => 'ACCOUNT_OPENING_BALANCE',
            'source_id' => $account->id,
            'reference_no' => 'OPENING-1016',
            'description' => 'Opening Balance - Cash Original Name',
            'amount' => 100000,
            'status' => JournalStatus::POSTED,
            'posted_at' => now(),
        ]);

        JournalEntryDetail::create([
            'journal_entry_id' => $journal->id,
            'account_id' => $account->id,
            'debit' => 100000,
            'credit' => 0,
            'description' => 'Opening Balance - Cash Original Name',
        ]);

        JournalEntryDetail::create([
            'journal_entry_id' => $journal->id,
            'account_id' => $equity->id,
            'debit' => 0,
            'credit' => 100000,
            'description' => 'Opening Balance - Cash Original Name',
        ]);

        $response = $this->putJson("/api/accounting/accounts/{$account->id}", [
            'name' => 'Cash Updated Name',
            'description' => 'Updated without changing opening balance.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Cash Updated Name')
            ->assertJsonPath('data.description', 'Updated without changing opening balance.');
    }

    public function test_rejects_opening_balance_when_equity_account_is_not_configured(): void
    {
        $category = AccountCategory::where('category_code', 'CASH_CASH_EQUIVALENT')->firstOrFail();

        $response = $this->postJson('/api/accounting/accounts', [
            'category_id' => $category->id,
            'code' => '1017',
            'name' => 'Cash Without Contra',
            'is_postable' => true,
            'status' => true,
            'opening_balance' => 500000,
            'opening_balance_date' => '2026-01-01',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['opening_balance']);

        $this->assertSame(
            'Opening Balance Equity account is not configured.',
            $response->json('errors.opening_balance.0')
        );

        $this->assertDatabaseMissing('acc_accounts', ['code' => '1017']);
    }

    public function test_rejects_opening_balance_when_fiscal_period_is_closed(): void
    {
        $category = AccountCategory::where('category_code', 'CASH_CASH_EQUIVALENT')->firstOrFail();
        $this->createOpeningBalanceEquityAccount();

        FiscalPeriod::create([
            'year' => 2026,
            'month' => 1,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'is_closed' => true,
        ]);

        $response = $this->postJson('/api/accounting/accounts', [
            'category_id' => $category->id,
            'code' => '1018',
            'name' => 'Cash Closed Period',
            'is_postable' => true,
            'status' => true,
            'opening_balance' => 100000,
            'opening_balance_date' => '2026-01-01',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['trx_date']);

        $this->assertDatabaseMissing('acc_accounts', ['code' => '1018']);
    }

    public function test_can_toggle_status()
    {
        $category = AccountCategory::where('category_code', 'CASH_CASH_EQUIVALENT')->first();
        $account = Account::factory()->create(['category_id' => $category->id, 'status' => true]);

        $response = $this->patchJson("/api/accounting/accounts/{$account->id}/toggle-status");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', false);
    }

    public function test_can_delete_account()
    {
        $category = AccountCategory::where('category_code', 'CASH_CASH_EQUIVALENT')->first();
        $account = Account::factory()->create(['category_id' => $category->id]);

        $response = $this->deleteJson("/api/accounting/accounts/{$account->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('acc_accounts', ['id' => $account->id]);
    }

    protected function createOpeningBalanceEquityAccount(): Account
    {
        $equityCategory = AccountCategory::where('category_code', 'EQUITY')->firstOrFail();

        return Account::factory()->create([
            'category_id' => $equityCategory->id,
            'code' => '3001',
            'name' => 'Opening Balance Equity',
            'is_postable' => true,
            'status' => true,
        ]);
    }
}
