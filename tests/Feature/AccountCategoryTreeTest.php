<?php

namespace ESolution\LaravelAccounting\Tests\Feature;

use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use ESolution\LaravelAccounting\Models\MonthlyBalance;
use ESolution\LaravelAccounting\Services\AccountCategoryTreeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountCategoryTreeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('db:seed', ['--class' => 'ESolution\\LaravelAccounting\\Database\\Seeders\\AccountCategorySeeder']);
    }

    public function test_account_categories_support_parent_children_tree(): void
    {
        $asset = AccountCategory::where('category_code', 'ASSET')->firstOrFail();
        $currentAsset = AccountCategory::where('category_code', 'CURRENT_ASSET')->firstOrFail();
        $cash = AccountCategory::where('category_code', 'CASH_CASH_EQUIVALENT')->firstOrFail();

        $this->assertSame($asset->id, $currentAsset->parent_id);
        $this->assertSame($currentAsset->id, $cash->parent_id);
        $this->assertGreaterThanOrEqual(4, $asset->descendantCategories()->count());

        $tree = app(AccountCategoryTreeService::class)->getTree();

        $this->assertCount(5, $tree);
        $this->assertSame('ASSET', $tree->firstWhere('category_code', 'ASSET')['type']);
    }

    public function test_account_categories_index_defaults_to_flat_categories_without_relations(): void
    {
        $parent = AccountCategory::create([
            'parent_id' => null,
            'type' => 'ASSET',
            'category_code' => 'PARENT_TEST',
            'category_name' => 'Parent Test',
            'report_type' => 'BS',
            'sequence_no' => 1,
            'status' => true,
        ]);

        $child = AccountCategory::create([
            'parent_id' => $parent->id,
            'type' => 'ASSET',
            'category_code' => 'CHILD_TEST_1',
            'category_name' => 'Child Test 1',
            'report_type' => 'BS',
            'sequence_no' => 1,
            'status' => true,
        ]);

        $response = $this->getJson('/api/accounting/categories');

        $response->assertOk();

        $data = collect($response->json('data'));
        $parentNode = $data->first(fn (array $item) => $item['id'] === $parent->id);
        $childNode = $data->first(fn (array $item) => $item['id'] === $child->id);

        $this->assertNotNull($parentNode);
        $this->assertNotNull($childNode);
        $this->assertArrayNotHasKey('children', $parentNode);
        $this->assertArrayNotHasKey('accounts', $parentNode);
        $this->assertArrayNotHasKey('children', $childNode);
        $this->assertArrayNotHasKey('accounts', $childNode);
    }

    public function test_account_categories_index_filters_by_parent_id_and_excludes_parent_itself(): void
    {
        $parent = AccountCategory::create([
            'parent_id' => null,
            'type' => 'ASSET',
            'category_code' => 'PARENT_FILTER_TEST',
            'category_name' => 'Parent Filter Test',
            'report_type' => 'BS',
            'sequence_no' => 1,
            'status' => true,
        ]);

        $child = AccountCategory::create([
            'parent_id' => $parent->id,
            'type' => 'ASSET',
            'category_code' => 'CHILD_FILTER_TEST',
            'category_name' => 'Child Filter Test',
            'report_type' => 'BS',
            'sequence_no' => 2,
            'status' => true,
        ]);

        AccountCategory::create([
            'parent_id' => $child->id,
            'type' => 'ASSET',
            'category_code' => 'GRANDCHILD_FILTER_TEST',
            'category_name' => 'Grandchild Filter Test',
            'report_type' => 'BS',
            'sequence_no' => 3,
            'status' => true,
        ]);

        $response = $this->getJson('/api/accounting/categories?parent_id='.$parent->id);

        $response->assertOk();

        $data = collect($response->json('data'));

        $this->assertTrue($data->every(fn (array $item) => $item['parent_id'] === $parent->id));
        $this->assertFalse($data->contains(fn (array $item) => $item['id'] === $parent->id));
        $this->assertTrue($data->every(fn (array $item) => ! array_key_exists('children', $item)));
        $this->assertTrue($data->every(fn (array $item) => ! array_key_exists('accounts', $item)));
        $this->assertSame($child->id, $data->first()['id']);
    }

    public function test_account_categories_index_can_return_root_only_tree(): void
    {
        $parent = AccountCategory::create([
            'parent_id' => null,
            'type' => 'ASSET',
            'category_code' => 'ROOT_PARENT_TEST',
            'category_name' => 'Root Parent Test',
            'report_type' => 'BS',
            'sequence_no' => 10,
            'status' => true,
        ]);

        $child = AccountCategory::create([
            'parent_id' => $parent->id,
            'type' => 'ASSET',
            'category_code' => 'ROOT_CHILD_TEST',
            'category_name' => 'Root Child Test',
            'report_type' => 'BS',
            'sequence_no' => 11,
            'status' => true,
        ]);

        $response = $this->getJson('/api/accounting/categories?root_only=true');

        $response->assertOk();

        $data = collect($response->json('data'));

        $this->assertTrue($data->contains(fn (array $item) => $item['id'] === $parent->id));
        $this->assertFalse($data->contains(fn (array $item) => $item['id'] === $child->id));
        $this->assertTrue($data->every(fn (array $item) => ($item['parent_id'] ?? null) === null));
        $this->assertTrue($data->every(fn (array $item) => ! array_key_exists('children', $item)));
        $this->assertTrue($data->every(fn (array $item) => ! array_key_exists('accounts', $item)));
    }

    public function test_account_categories_index_can_include_children_and_accounts(): void
    {
        $parent = AccountCategory::create([
            'parent_id' => null,
            'type' => 'ASSET',
            'category_code' => 'WITH_CHILDREN_TEST',
            'category_name' => 'With Children Test',
            'report_type' => 'BS',
            'sequence_no' => 1,
            'status' => true,
        ]);

        $child = AccountCategory::create([
            'parent_id' => $parent->id,
            'type' => 'ASSET',
            'category_code' => 'WITH_CHILDREN_CHILD_TEST',
            'category_name' => 'Child Under Root',
            'report_type' => 'BS',
            'sequence_no' => 2,
            'status' => true,
        ]);

        $account = Account::create([
            'category_id' => $parent->id,
            'code' => '9999',
            'name' => 'Test Account',
            'is_postable' => true,
            'status' => true,
        ]);

        $response = $this->getJson('/api/accounting/categories?with=children,accounts');

        $response->assertOk();

        $data = collect($response->json('data'));
        $node = $data->first(fn (array $item) => $item['id'] === $parent->id);

        $this->assertArrayHasKey('children', $node);
        $this->assertArrayHasKey('accounts', $node);
        $this->assertSame($child->id, $node['children'][0]['id']);
        $this->assertSame($account->id, $node['accounts'][0]['id']);
    }

    public function test_account_categories_index_supports_with_array_syntax(): void
    {
        $category = AccountCategory::create([
            'parent_id' => null,
            'type' => 'ASSET',
            'category_code' => 'WITH_ARRAY_TEST',
            'category_name' => 'With Array Test',
            'report_type' => 'BS',
            'sequence_no' => 1,
            'status' => true,
        ]);

        $response = $this->getJson('/api/accounting/categories?with[]=accounts');

        $response->assertOk();

        $data = collect($response->json('data'));
        $node = $data->first(fn (array $item) => $item['id'] === $category->id);

        $this->assertArrayHasKey('accounts', $node);
    }

    public function test_account_categories_index_can_include_balance_recursively(): void
    {
        $asset = AccountCategory::create([
            'parent_id' => null,
            'type' => 'ASSET',
            'category_code' => 'BALANCE_ROOT_TEST',
            'category_name' => 'Assets Test',
            'report_type' => 'BS',
            'sequence_no' => 100,
            'status' => true,
        ]);

        $currentAsset = AccountCategory::create([
            'parent_id' => $asset->id,
            'type' => 'ASSET',
            'category_code' => 'BALANCE_CHILD_TEST',
            'category_name' => 'Current Assets Test',
            'report_type' => 'BS',
            'sequence_no' => 101,
            'status' => true,
        ]);

        $fixedAsset = AccountCategory::create([
            'parent_id' => $asset->id,
            'type' => 'ASSET',
            'category_code' => 'BALANCE_FIXED_TEST',
            'category_name' => 'Fixed Assets Test',
            'report_type' => 'BS',
            'sequence_no' => 102,
            'status' => true,
        ]);

        $cash = Account::create([
            'category_id' => $currentAsset->id,
            'code' => '9101',
            'name' => 'Cash Test',
            'is_postable' => true,
            'status' => true,
        ]);

        $bank = Account::create([
            'category_id' => $currentAsset->id,
            'code' => '9102',
            'name' => 'Bank Test',
            'is_postable' => true,
            'status' => true,
        ]);

        $vehicle = Account::create([
            'category_id' => $fixedAsset->id,
            'code' => '9103',
            'name' => 'Vehicle Test',
            'is_postable' => true,
            'status' => true,
        ]);

        $this->storeMonthlyBalance($cash->id, 2026, 7, 10000);
        $this->storeMonthlyBalance($bank->id, 2026, 7, 5000);
        $this->storeMonthlyBalance($vehicle->id, 2026, 7, 20000);

        $response = $this->getJson('/api/accounting/categories?with=balance&year=2026&month=7');

        $response->assertOk();

        $data = collect($response->json('data'));

        $assetNode = $data->firstWhere('id', $asset->id);
        $currentAssetNode = $data->firstWhere('id', $currentAsset->id);
        $fixedAssetNode = $data->firstWhere('id', $fixedAsset->id);

        $this->assertSame(35000.0, (float) data_get($assetNode, 'balance'));
        $this->assertSame(15000.0, (float) data_get($currentAssetNode, 'balance'));
        $this->assertSame(20000.0, (float) data_get($fixedAssetNode, 'balance'));
    }

    public function test_account_categories_index_can_include_accounts_and_balance(): void
    {
        $category = AccountCategory::create([
            'parent_id' => null,
            'type' => 'ASSET',
            'category_code' => 'ACCOUNT_BALANCE_TEST',
            'category_name' => 'Account Balance Test',
            'report_type' => 'BS',
            'sequence_no' => 110,
            'status' => true,
        ]);

        $cash = Account::create([
            'category_id' => $category->id,
            'code' => '9201',
            'name' => 'Cash Account Test',
            'is_postable' => true,
            'status' => true,
        ]);

        $bank = Account::create([
            'category_id' => $category->id,
            'code' => '9202',
            'name' => 'Bank Account Test',
            'is_postable' => true,
            'status' => true,
        ]);

        $this->storeMonthlyBalance($cash->id, 2026, 7, 1500000);
        $this->storeMonthlyBalance($bank->id, 2026, 7, 2500000);

        $response = $this->getJson('/api/accounting/categories?with=accounts,balance&year=2026&month=7');

        $response->assertOk();

        $node = collect($response->json('data'))->firstWhere('id', $category->id);

        $this->assertSame(4000000.0, (float) data_get($node, 'balance'));
        $this->assertSame(1500000.0, (float) data_get($node, 'accounts.0.balance'));
        $this->assertSame(2500000.0, (float) data_get($node, 'accounts.1.balance'));
    }

    public function test_account_categories_index_returns_zero_balance_for_categories_without_accounts_or_children(): void
    {
        $category = AccountCategory::create([
            'parent_id' => null,
            'type' => 'ASSET',
            'category_code' => 'EMPTY_BALANCE_TEST',
            'category_name' => 'Empty Balance Test',
            'report_type' => 'BS',
            'sequence_no' => 120,
            'status' => true,
        ]);

        $response = $this->getJson('/api/accounting/categories?with=balance&year=2026&month=7');

        $response->assertOk()
            ->assertJsonPath('data.'.collect($response->json('data'))->search(fn (array $item) => $item['id'] === $category->id).'.balance', 0);
    }

    public function test_account_categories_index_returns_child_balance_for_parent_without_direct_accounts(): void
    {
        $parent = AccountCategory::create([
            'parent_id' => null,
            'type' => 'ASSET',
            'category_code' => 'PARENT_ONLY_BALANCE_TEST',
            'category_name' => 'Parent Only Balance Test',
            'report_type' => 'BS',
            'sequence_no' => 130,
            'status' => true,
        ]);

        $child = AccountCategory::create([
            'parent_id' => $parent->id,
            'type' => 'ASSET',
            'category_code' => 'CHILD_ONLY_BALANCE_TEST',
            'category_name' => 'Child Only Balance Test',
            'report_type' => 'BS',
            'sequence_no' => 131,
            'status' => true,
        ]);

        $account = Account::create([
            'category_id' => $child->id,
            'code' => '9301',
            'name' => 'Child Cash Test',
            'is_postable' => true,
            'status' => true,
        ]);

        $this->storeMonthlyBalance($account->id, 2026, 7, 750000);

        $response = $this->getJson('/api/accounting/categories?with=balance&year=2026&month=7');

        $response->assertOk();

        $data = collect($response->json('data'));

        $this->assertSame(750000.0, (float) data_get($data->firstWhere('id', $parent->id), 'balance'));
        $this->assertSame(750000.0, (float) data_get($data->firstWhere('id', $child->id), 'balance'));
    }

    protected function storeMonthlyBalance(string $accountId, int $year, int $month, float $endingBalance): void
    {
        MonthlyBalance::create([
            'fiscal_year' => $year,
            'fiscal_month' => $month,
            'account_id' => $accountId,
            'opening_balance' => $endingBalance,
            'total_debit' => 0,
            'total_credit' => 0,
            'ending_balance' => $endingBalance,
            'journal_count' => 0,
            'closed_at' => null,
            'closed_by' => null,
        ]);
    }
}
