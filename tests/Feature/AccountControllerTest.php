<?php

namespace ESolution\LaravelAccounting\Tests\Feature;

use ESolution\LaravelAccounting\Enums\JournalStatus;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
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
        Account::factory()->create(['category_id' => $category->id, 'code' => '1001', 'name' => 'Cash']);

        $response = $this->getJson('/api/accounting/accounts');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.code', '1001');
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
            'status' => true,
        ];

        $response = $this->postJson('/api/accounting/accounts', $data);

        $response->assertStatus(201)
            ->assertJsonPath('data.code', '1002');

        $this->assertDatabaseHas('acc_accounts', ['code' => '1002']);
    }

    public function test_can_show_account()
    {
        $category = AccountCategory::where('category_code', 'CASH_CASH_EQUIVALENT')->first();
        $account = Account::factory()->create(['category_id' => $category->id]);

        $response = $this->getJson("/api/accounting/accounts/{$account->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $account->id)
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
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'New Name');
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
}
