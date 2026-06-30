<?php

namespace ESolution\LaravelAccounting\Tests\Feature;

use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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
            ->assertJsonPath('data.id', $account->id);
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
