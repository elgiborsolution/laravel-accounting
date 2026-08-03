<?php

namespace ESolution\LaravelAccounting\Tests\Feature;

use ESolution\LaravelAccounting\Contracts\AfterApiHook;
use ESolution\LaravelAccounting\Contracts\BeforeApiHook;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use ESolution\LaravelAccounting\Models\JournalEntry;
use ESolution\LaravelAccounting\Models\Service;
use ESolution\LaravelAccounting\Support\ApiContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MutationHookSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        MutationHookAudit::$before = [];
        MutationHookAudit::$after = [];

        config()->set('accounting.hooks.before', MutationHookBeforeTestHook::class);
        config()->set('accounting.hooks.after', MutationHookAfterTestHook::class);

        $this->artisan('db:seed', ['--class' => 'ESolution\LaravelAccounting\Database\Seeders\AccountCategorySeeder']);
    }

    public function test_get_endpoints_do_not_execute_hooks(): void
    {
        $category = AccountCategory::where('category_code', 'CASH_CASH_EQUIVALENT')->firstOrFail();

        Account::factory()->create([
            'category_id' => $category->id,
            'code' => '9001',
            'name' => 'Read Account',
        ]);

        Service::create([
            'service_code' => 'READ-SERVICE',
            'service_name' => 'Read Service',
            'module_name' => 'FIN',
            'status' => true,
        ]);

        $this->getJson('/api/accounting/accounts')->assertOk();
        $this->getJson('/api/accounting/categories')->assertOk();
        $this->getJson('/api/accounting/services')->assertOk();

        $this->assertSame([], MutationHookAudit::$before);
        $this->assertSame([], MutationHookAudit::$after);
    }

    public function test_validation_failure_does_not_execute_hooks(): void
    {
        $response = $this->postJson('/api/accounting/accounts', [
            'name' => 'Invalid Account',
        ]);

        $response->assertStatus(422);

        $this->assertSame([], MutationHookAudit::$before);
        $this->assertSame([], MutationHookAudit::$after);
    }

    public function test_account_mutation_endpoints_execute_hooks(): void
    {
        $category = AccountCategory::where('category_code', 'CASH_CASH_EQUIVALENT')->firstOrFail();

        $createResponse = $this->postJson('/api/accounting/accounts', [
            'category_id' => $category->id,
            'code' => '9101',
            'name' => 'Hook Account',
            'is_postable' => true,
            'status' => true,
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.name', 'Hook Account [before-hook]')
            ->assertJsonPath('data.hook_action', 'accounts.store');

        $accountId = $createResponse->json('data.id');

        $this->putJson("/api/accounting/accounts/{$accountId}", [
            'name' => 'Hook Account Updated',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Hook Account Updated [before-hook]')
            ->assertJsonPath('data.hook_action', 'accounts.update');

        $this->patchJson("/api/accounting/accounts/{$accountId}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.hook_action', 'accounts.toggle-status');

        $this->deleteJson("/api/accounting/accounts/{$accountId}")
            ->assertOk();

        $this->assertSame(
            ['accounts.store', 'accounts.update', 'accounts.toggle-status', 'accounts.destroy'],
            array_column(MutationHookAudit::$before, 'action')
        );

        $this->assertSame(
            ['accounts.store', 'accounts.update', 'accounts.toggle-status', 'accounts.destroy'],
            array_column(MutationHookAudit::$after, 'action')
        );
    }

    public function test_category_mutation_endpoints_execute_hooks(): void
    {
        $parent = AccountCategory::where('category_code', 'ASSET')->firstOrFail();

        $createResponse = $this->postJson('/api/accounting/categories', [
            'parent_id' => $parent->id,
            'type' => 'ASSET',
            'category_code' => 'HOOK_CATEGORY',
            'category_name' => 'Hook Category',
            'report_type' => 'BS',
            'sequence_no' => 99,
            'status' => true,
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.category_name', 'Hook Category [before-hook]')
            ->assertJsonPath('data.hook_action', 'categories.store');

        $categoryId = $createResponse->json('data.id');

        $this->putJson("/api/accounting/categories/{$categoryId}", [
            'category_name' => 'Hook Category Updated',
        ])->assertOk()
            ->assertJsonPath('data.category_name', 'Hook Category Updated [before-hook]')
            ->assertJsonPath('data.hook_action', 'categories.update');

        $this->patchJson("/api/accounting/categories/{$categoryId}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.hook_action', 'categories.toggle-status');

        $this->deleteJson("/api/accounting/categories/{$categoryId}")
            ->assertOk();

        $this->assertSame(
            ['categories.store', 'categories.update', 'categories.toggle-status', 'categories.destroy'],
            array_column(MutationHookAudit::$before, 'action')
        );

        $this->assertSame(
            ['categories.store', 'categories.update', 'categories.toggle-status', 'categories.destroy'],
            array_column(MutationHookAudit::$after, 'action')
        );
    }

    public function test_journal_mutation_endpoints_execute_hooks(): void
    {
        [$debitAccount, $creditAccount] = $this->createPostingAccounts();

        $manualResponse = $this->postJson('/api/accounting/journals', [
            'trx_date' => '2026-08-03',
            'reference_no' => 'MANUAL-001',
            'details' => [
                [
                    'account_id' => $debitAccount->id,
                    'type' => 'D',
                    'amount' => 100000,
                ],
                [
                    'account_id' => $creditAccount->id,
                    'type' => 'K',
                    'amount' => 100000,
                ],
            ],
        ]);

        $manualResponse->assertCreated()
            ->assertJsonPath('data.reference_no', 'AFTER-MANUAL-001-BEFORE');

        $openingResponse = $this->postJson('/api/accounting/opening-balances', [
            'trx_date' => '2026-01-01',
            'reference_no' => 'OPENING-001',
            'details' => [
                [
                    'account_id' => $debitAccount->id,
                    'amount' => 100000,
                ],
                [
                    'account_id' => $creditAccount->id,
                    'amount' => 100000,
                ],
            ],
        ]);

        $openingResponse->assertCreated()
            ->assertJsonPath('data.reference_no', 'AFTER-OPENING-001-BEFORE');

        $journal = JournalEntry::query()->where('source_type', 'MANUAL_JOURNAL')->firstOrFail();

        $this->postJson("/api/accounting/journals/{$journal->id}/reverse", [
            'reason' => 'Hook reverse',
        ])->assertCreated();

        $this->assertSame(
            ['journals.store', 'opening-balances.store', 'journals.reverse'],
            array_column(MutationHookAudit::$before, 'action')
        );

        $this->assertSame(
            ['journals.store', 'opening-balances.store', 'journals.reverse'],
            array_column(MutationHookAudit::$after, 'action')
        );
    }

    public function test_service_mutation_endpoints_execute_hooks(): void
    {
        $service = Service::create([
            'service_code' => 'HOOK-SERVICE',
            'service_name' => 'Hook Service',
            'module_name' => 'FIN',
            'status' => true,
        ]);

        $this->patchJson("/api/accounting/services/{$service->id}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.hook_action', 'services.toggle-status');

        $this->deleteJson("/api/accounting/services/{$service->id}")
            ->assertOk();

        $this->assertSame(
            ['services.toggle-status', 'services.destroy'],
            array_column(MutationHookAudit::$before, 'action')
        );

        $this->assertSame(
            ['services.toggle-status', 'services.destroy'],
            array_column(MutationHookAudit::$after, 'action')
        );
    }

    protected function createPostingAccounts(): array
    {
        $assetCategory = AccountCategory::where('category_code', 'CASH_CASH_EQUIVALENT')->firstOrFail();
        $liabilityCategory = AccountCategory::where('category_code', 'CURRENT_LIABILITY')->firstOrFail();
        $equityCategory = AccountCategory::where('category_code', 'EQUITY')->firstOrFail();

        $debitAccount = Account::factory()->create([
            'category_id' => $assetCategory->id,
            'code' => '9201',
            'name' => 'Hook Debit',
            'is_postable' => true,
            'status' => true,
        ]);

        $creditAccount = Account::factory()->create([
            'category_id' => $equityCategory->id,
            'code' => '3201',
            'name' => 'Hook Credit',
            'is_postable' => true,
            'status' => true,
        ]);

        $liabilityAccount = Account::factory()->create([
            'category_id' => $liabilityCategory->id,
            'code' => '2201',
            'name' => 'Hook Liability',
            'is_postable' => true,
            'status' => true,
        ]);

        return [$debitAccount, $creditAccount, $liabilityAccount];
    }
}

class MutationHookAudit
{
    public static array $before = [];

    public static array $after = [];
}

class MutationHookBeforeTestHook implements BeforeApiHook
{
    public function handle(ApiContext $context): void
    {
        $payload = $context->payload();

        if (isset($payload['name'])) {
            $payload['name'] .= ' [before-hook]';
        }

        if (isset($payload['category_name'])) {
            $payload['category_name'] .= ' [before-hook]';
        }

        if (isset($payload['reference_no'])) {
            $payload['reference_no'] .= '-BEFORE';
        }

        $context->setPayload($payload);

        MutationHookAudit::$before[] = [
            'action' => $context->action(),
            'payload' => $payload,
        ];
    }
}

class MutationHookAfterTestHook implements AfterApiHook
{
    public function handle(ApiContext $context, mixed $result): mixed
    {
        MutationHookAudit::$after[] = [
            'action' => $context->action(),
            'result_type' => is_object($result) ? $result::class : gettype($result),
        ];

        if (is_object($result) && method_exists($result, 'setAttribute')) {
            $result->setAttribute('hook_action', $context->action());
        }

        if ($result instanceof JournalEntry && $result->reference_no) {
            $result->reference_no = 'AFTER-'.$result->reference_no;
        }

        return $result;
    }
}
