<?php

namespace ESolution\LaravelAccounting\Tests\Feature;

use ESolution\LaravelAccounting\Models\AccountCategory;
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

    public function test_account_categories_support_parent_children_tree()
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
}
