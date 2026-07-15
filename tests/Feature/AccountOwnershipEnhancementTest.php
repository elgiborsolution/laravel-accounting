<?php

namespace ESolution\LaravelAccounting\Tests\Feature;

use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AccountOwnershipEnhancementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->artisan('db:seed', ['--class' => 'ESolution\LaravelAccounting\Database\Seeders\AccountCategorySeeder']);
    }

    public function test_account_index_returns_only_central_accounts_by_default(): void
    {
        $category = $this->cashCategory();

        Account::factory()->create([
            'category_id' => $category->id,
            'code' => '1001',
            'name' => 'Central Cash',
            'tenant_id' => null,
        ]);

        Account::factory()->create([
            'category_id' => $category->id,
            'code' => '2001',
            'name' => 'Tenant Cash',
            'tenant_id' => 'tenant-a',
        ]);

        $response = $this->getJson('/api/accounting/accounts');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', '1001')
            ->assertJsonPath('data.0.tenant_id', null);
    }

    public function test_account_index_can_include_matching_tenant_accounts(): void
    {
        $category = $this->cashCategory();

        Account::factory()->create([
            'category_id' => $category->id,
            'code' => '1001',
            'name' => 'Central Cash',
            'tenant_id' => null,
        ]);

        Account::factory()->create([
            'category_id' => $category->id,
            'code' => '2001',
            'name' => 'Tenant Cash A',
            'tenant_id' => 'tenant-a',
        ]);

        Account::factory()->create([
            'category_id' => $category->id,
            'code' => '3001',
            'name' => 'Tenant Cash B',
            'tenant_id' => 'tenant-b',
        ]);

        $response = $this->withHeader('X-Tenant', 'tenant-a')->getJson('/api/accounting/accounts');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', '1001')
            ->assertJsonPath('data.1.code', '2001');

        $this->assertSame('tenant-a', data_get($response->json('data.1'), 'tenant_id'));
    }

    public function test_account_index_can_filter_by_category_id(): void
    {
        $cashCategory = $this->cashCategory();
        $receivableCategory = AccountCategory::where('category_code', 'ACCOUNT_RECEIVABLE')->firstOrFail();

        Account::factory()->create([
            'category_id' => $cashCategory->id,
            'code' => '1001',
            'name' => 'Cash Central',
            'tenant_id' => null,
        ]);

        Account::factory()->create([
            'category_id' => $receivableCategory->id,
            'code' => '2001',
            'name' => 'Receivable Central',
            'tenant_id' => null,
        ]);

        $response = $this->getJson('/api/accounting/accounts?category_id='.$cashCategory->id);

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', '1001');
    }

    public function test_account_index_category_filter_works_with_tenant_visibility(): void
    {
        $cashCategory = $this->cashCategory();
        $receivableCategory = AccountCategory::where('category_code', 'ACCOUNT_RECEIVABLE')->firstOrFail();

        Account::factory()->create([
            'category_id' => $cashCategory->id,
            'code' => '1001',
            'name' => 'Cash Central',
            'tenant_id' => null,
        ]);

        Account::factory()->create([
            'category_id' => $cashCategory->id,
            'code' => '1002',
            'name' => 'Cash Tenant A',
            'tenant_id' => 'tenant-a',
        ]);

        Account::factory()->create([
            'category_id' => $receivableCategory->id,
            'code' => '2001',
            'name' => 'Receivable Tenant A',
            'tenant_id' => 'tenant-a',
        ]);

        $response = $this->withHeader('X-Tenant', 'tenant-a')->getJson('/api/accounting/accounts?category_id='.$cashCategory->id);

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', '1001')
            ->assertJsonPath('data.1.code', '1002');
    }

    public function test_account_index_rejects_invalid_category_id(): void
    {
        $response = $this->getJson('/api/accounting/accounts?category_id=not-a-valid-uuid');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_account_index_ignores_tenant_id_query_parameter_when_no_tenant_is_resolved(): void
    {
        $category = $this->cashCategory();

        Account::factory()->create([
            'category_id' => $category->id,
            'code' => '1001',
            'name' => 'Central Cash',
            'tenant_id' => null,
        ]);

        Account::factory()->create([
            'category_id' => $category->id,
            'code' => '2001',
            'name' => 'Tenant Cash',
            'tenant_id' => 'tenant-a',
        ]);

        $response = $this->getJson('/api/accounting/accounts?tenant_id=tenant-a');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', '1001');
    }

    public function test_account_store_persists_tenant_id(): void
    {
        $category = $this->cashCategory();

        $response = $this->postJson('/api/accounting/accounts', [
            'category_id' => $category->id,
            'code' => '4001',
            'name' => 'Tenant-Owned Account',
            'tenant_id' => 'tenant-a',
            'status' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.code', '4001')
            ->assertJsonPath('data.tenant_id', 'tenant-a');

        $this->assertDatabaseHas('acc_accounts', [
            'code' => '4001',
            'tenant_id' => 'tenant-a',
        ]);
    }

    public function test_account_update_can_change_tenant_id(): void
    {
        $category = $this->cashCategory();

        $account = Account::factory()->create([
            'category_id' => $category->id,
            'code' => '5001',
            'name' => 'Movable Account',
            'tenant_id' => null,
        ]);

        $response = $this->putJson("/api/accounting/accounts/{$account->id}", [
            'tenant_id' => 'tenant-b',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.id', $account->id)
            ->assertJsonPath('data.tenant_id', 'tenant-b');

        $this->assertDatabaseHas('acc_accounts', [
            'id' => $account->id,
            'tenant_id' => 'tenant-b',
        ]);
    }

    public function test_category_accounts_follow_tenant_filter_when_requested(): void
    {
        $category = $this->cashCategory();

        Account::factory()->create([
            'category_id' => $category->id,
            'code' => '1001',
            'name' => 'Central Cash',
            'tenant_id' => null,
        ]);

        Account::factory()->create([
            'category_id' => $category->id,
            'code' => '2001',
            'name' => 'Tenant Cash A',
            'tenant_id' => 'tenant-a',
        ]);

        Account::factory()->create([
            'category_id' => $category->id,
            'code' => '3001',
            'name' => 'Tenant Cash B',
            'tenant_id' => 'tenant-b',
        ]);

        $response = $this->withHeader('X-Tenant', 'tenant-a')->getJson('/api/accounting/categories?with=accounts');

        $response->assertOk();

        $node = collect($response->json('data'))->firstWhere('id', $category->id);

        $this->assertCount(2, data_get($node, 'accounts'));
        $this->assertSame(['1001', '2001'], collect(data_get($node, 'accounts'))->pluck('code')->all());
    }

    public function test_account_show_respects_tenant_visibility(): void
    {
        $category = $this->cashCategory();

        $account = Account::factory()->create([
            'category_id' => $category->id,
            'code' => '6001',
            'name' => 'Tenant Visible Account',
            'tenant_id' => 'tenant-a',
        ]);

        $response = $this->withHeader('X-Tenant', 'tenant-a')->getJson("/api/accounting/accounts/{$account->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $account->id)
            ->assertJsonPath('data.tenant_id', 'tenant-a');

        if (function_exists('tenancy') && tenancy()->initialized) {
            tenancy()->end();
        }

        $this->withoutHeader('X-Tenant');

        $forbiddenResponse = $this->getJson("/api/accounting/accounts/{$account->id}");

        $forbiddenResponse->assertNotFound();
    }

    protected function cashCategory(): AccountCategory
    {
        return AccountCategory::where('category_code', 'CASH_CASH_EQUIVALENT')->firstOrFail();
    }
}
