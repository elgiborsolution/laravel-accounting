<?php

namespace ESolution\LaravelAccounting\Tests\Feature;

use ESolution\LaravelAccounting\Enums\JournalStatus;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use ESolution\LaravelAccounting\Models\FiscalPeriod;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OpeningBalanceControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->configureSqliteConnection('single');
        $this->configureSqliteConnection('tenant');
        $this->configureSqliteConnection('master');
    }

    public function test_it_creates_opening_balance_successfully_in_single_database_mode(): void
    {
        $this->useSingleDatabaseMode('single');
        $this->createAllAccountingTables('single');

        [$assetAccount, $liabilityAccount, $equityAccount] = $this->seedOpeningBalanceAccounts('single');

        $response = $this->postJson('/api/accounting/opening-balances', [
            'trx_date' => '2026-01-01',
            'reference_no' => 'OPENING-2026',
            'description' => 'Opening Balance Tahun 2026',
            'details' => [
                [
                    'account_id' => $assetAccount->id,
                    'amount' => 1000000,
                ],
                [
                    'account_id' => $liabilityAccount->id,
                    'amount' => -500000,
                ],
                [
                    'account_id' => $equityAccount->id,
                    'amount' => 1500000,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Opening balance created successfully')
            ->assertJsonPath('data.reference_no', 'OPENING-2026')
            ->assertJsonPath('data.amount', 1500000)
            ->assertJsonPath('data.status', 'posted');

        $journalId = $response->json('data.id');

        $this->assertDatabaseHas('acc_journal_entries', [
            'id' => $journalId,
            'source_type' => 'OPENING_BALANCE',
            'reference_no' => 'OPENING-2026',
            'amount' => 1500000,
            'status' => JournalStatus::POSTED->value,
        ], 'single');

        $this->assertDatabaseHas('acc_journal_entry_details', [
            'journal_entry_id' => $journalId,
            'account_id' => $assetAccount->id,
            'debit' => 1000000,
            'credit' => 0,
        ], 'single');

        $this->assertDatabaseHas('acc_journal_entry_details', [
            'journal_entry_id' => $journalId,
            'account_id' => $liabilityAccount->id,
            'debit' => 500000,
            'credit' => 0,
        ], 'single');

        $this->assertDatabaseHas('acc_journal_entry_details', [
            'journal_entry_id' => $journalId,
            'account_id' => $equityAccount->id,
            'debit' => 0,
            'credit' => 1500000,
        ], 'single');
    }

    public function test_it_rejects_unbalanced_opening_balance(): void
    {
        $this->useSingleDatabaseMode('single');
        $this->createAllAccountingTables('single');

        [$assetAccount, $liabilityAccount] = $this->seedOpeningBalanceAccounts('single');

        $response = $this->postJson('/api/accounting/opening-balances', [
            'trx_date' => '2026-01-01',
            'reference_no' => 'OPENING-2026',
            'details' => [
                [
                    'account_id' => $assetAccount->id,
                    'amount' => 1000000,
                ],
                [
                    'account_id' => $liabilityAccount->id,
                    'amount' => 500000,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['details']);
    }

    public function test_it_rejects_missing_accounts(): void
    {
        $this->useSingleDatabaseMode('single');
        $this->createAllAccountingTables('single');

        $response = $this->postJson('/api/accounting/opening-balances', [
            'trx_date' => '2026-01-01',
            'reference_no' => 'OPENING-2026',
            'details' => [
                [
                    'account_id' => '11111111-1111-1111-1111-111111111111',
                    'amount' => 1000000,
                ],
                [
                    'account_id' => '22222222-2222-2222-2222-222222222222',
                    'amount' => -1000000,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['details.0.account_id', 'details.1.account_id']);
    }

    public function test_it_rejects_inactive_accounts(): void
    {
        $this->useSingleDatabaseMode('single');
        $this->createAllAccountingTables('single');

        $assetCategory = $this->seedCategory('single', 'ASSET', 'CASH_CASH_EQUIVALENT', 'Cash & Cash Equivalent', 'BS', 1);
        $liabilityCategory = $this->seedCategory('single', 'LIABILITY', 'CURRENT_LIABILITY', 'Current Liability', 'BS', 2);

        $inactiveAccount = $this->createAccount('single', $assetCategory->id, [
            'code' => '1001',
            'name' => 'Kas',
            'is_postable' => true,
            'status' => false,
        ]);
        $liabilityAccount = $this->createAccount('single', $liabilityCategory->id, [
            'code' => '2001',
            'name' => 'Hutang',
            'is_postable' => true,
            'status' => true,
        ]);

        $response = $this->postJson('/api/accounting/opening-balances', [
            'trx_date' => '2026-01-01',
            'reference_no' => 'OPENING-2026',
            'details' => [
                [
                    'account_id' => $inactiveAccount->id,
                    'amount' => 1000000,
                ],
                [
                    'account_id' => $liabilityAccount->id,
                    'amount' => -1000000,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['details.0.account_id']);
        $this->assertSame("Account is inactive: {$inactiveAccount->id}", $response->json('errors')['details.0.account_id'][0]);
    }

    public function test_it_rejects_non_postable_accounts(): void
    {
        $this->useSingleDatabaseMode('single');
        $this->createAllAccountingTables('single');

        $assetCategory = $this->seedCategory('single', 'ASSET', 'CASH_CASH_EQUIVALENT', 'Cash & Cash Equivalent', 'BS', 1);
        $liabilityCategory = $this->seedCategory('single', 'LIABILITY', 'CURRENT_LIABILITY', 'Current Liability', 'BS', 2);

        $nonPostableAccount = $this->createAccount('single', $assetCategory->id, [
            'code' => '1001',
            'name' => 'Kas',
            'is_postable' => false,
            'status' => true,
        ]);
        $liabilityAccount = $this->createAccount('single', $liabilityCategory->id, [
            'code' => '2001',
            'name' => 'Hutang',
            'is_postable' => true,
            'status' => true,
        ]);

        $response = $this->postJson('/api/accounting/opening-balances', [
            'trx_date' => '2026-01-01',
            'reference_no' => 'OPENING-2026',
            'details' => [
                [
                    'account_id' => $nonPostableAccount->id,
                    'amount' => 1000000,
                ],
                [
                    'account_id' => $liabilityAccount->id,
                    'amount' => -1000000,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['details.0.account_id']);
        $this->assertSame("Account is not postable: {$nonPostableAccount->id}", $response->json('errors')['details.0.account_id'][0]);
    }

    public function test_it_rejects_duplicate_accounts(): void
    {
        $this->useSingleDatabaseMode('single');
        $this->createAllAccountingTables('single');

        $assetCategory = $this->seedCategory('single', 'ASSET', 'CASH_CASH_EQUIVALENT', 'Cash & Cash Equivalent', 'BS', 1);

        $account = $this->createAccount('single', $assetCategory->id, [
            'code' => '1001',
            'name' => 'Kas',
            'is_postable' => true,
            'status' => true,
        ]);

        $response = $this->postJson('/api/accounting/opening-balances', [
            'trx_date' => '2026-01-01',
            'reference_no' => 'OPENING-2026',
            'details' => [
                [
                    'account_id' => $account->id,
                    'amount' => 1000000,
                ],
                [
                    'account_id' => $account->id,
                    'amount' => -1000000,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['details.1.account_id']);
        $this->assertSame('Duplicate account is not allowed.', $response->json('errors')['details.1.account_id'][0]);
    }

    public function test_it_rejects_closed_fiscal_periods(): void
    {
        $this->useSingleDatabaseMode('single');
        $this->createAllAccountingTables('single');

        [$assetAccount, $liabilityAccount] = $this->seedOpeningBalanceAccounts('single');
        FiscalPeriod::create([
            'year' => 2026,
            'month' => 1,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'is_closed' => true,
            'closed_at' => now(),
            'closed_by' => null,
        ]);

        $response = $this->postJson('/api/accounting/opening-balances', [
            'trx_date' => '2026-01-01',
            'reference_no' => 'OPENING-2026',
            'details' => [
                [
                    'account_id' => $assetAccount->id,
                    'amount' => 1000000,
                ],
                [
                    'account_id' => $liabilityAccount->id,
                    'amount' => -1000000,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['trx_date']);
    }

    public function test_it_rejects_duplicate_opening_balance_requests(): void
    {
        $this->useSingleDatabaseMode('single');
        $this->createAllAccountingTables('single');

        [$assetAccount, $liabilityAccount, $equityAccount] = $this->seedOpeningBalanceAccounts('single');

        $payload = [
            'trx_date' => '2026-01-01',
            'reference_no' => 'OPENING-2026',
            'details' => [
                [
                    'account_id' => $assetAccount->id,
                    'amount' => 1000000,
                ],
                [
                    'account_id' => $liabilityAccount->id,
                    'amount' => -500000,
                ],
                [
                    'account_id' => $equityAccount->id,
                    'amount' => 1500000,
                ],
            ],
        ];

        $this->postJson('/api/accounting/opening-balances', $payload)->assertCreated();

        $response = $this->postJson('/api/accounting/opening-balances', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['source_type']);
        $this->assertSame('Opening balance has already been created.', $response->json('errors')['source_type'][0]);
    }

    public function test_it_supports_multi_tenant_routes_with_shared_master_data(): void
    {
        $this->useTenantWithSharedMasterMode();
        $this->createMasterTables('master');
        $this->createTransactionTables('tenant');

        [$assetAccount, $liabilityAccount, $equityAccount] = $this->seedOpeningBalanceAccounts('master');

        $response = $this->postJson('/api/accounting/tenant-a/opening-balances', [
            'trx_date' => '2026-01-01',
            'reference_no' => 'OPENING-TENANT',
            'details' => [
                [
                    'account_id' => $assetAccount->id,
                    'amount' => 1000000,
                ],
                [
                    'account_id' => $liabilityAccount->id,
                    'amount' => -500000,
                ],
                [
                    'account_id' => $equityAccount->id,
                    'amount' => 1500000,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.amount', 1500000);

        $journalId = $response->json('data.id');

        $this->assertDatabaseHas('acc_journal_entries', [
            'id' => $journalId,
            'source_type' => 'OPENING_BALANCE',
            'reference_no' => 'OPENING-TENANT',
            'amount' => 1500000,
            'status' => JournalStatus::POSTED->value,
        ], 'tenant');

        $this->assertDatabaseHas('acc_journal_entry_details', [
            'journal_entry_id' => $journalId,
            'account_id' => $assetAccount->id,
        ], 'tenant');
    }

    private function seedOpeningBalanceAccounts(string $connection): array
    {
        $assetCategory = $this->seedCategory($connection, 'ASSET', 'CASH_CASH_EQUIVALENT', 'Cash & Cash Equivalent', 'BS', 1);
        $liabilityCategory = $this->seedCategory($connection, 'LIABILITY', 'CURRENT_LIABILITY', 'Current Liability', 'BS', 2);
        $equityCategory = $this->seedCategory($connection, 'EQUITY', 'EQUITY', 'Equity', 'BS', 3);

        $assetAccount = $this->createAccount($connection, $assetCategory->id, [
            'code' => '1001',
            'name' => 'Kas',
            'is_postable' => true,
            'status' => true,
        ]);

        $liabilityAccount = $this->createAccount($connection, $liabilityCategory->id, [
            'code' => '2001',
            'name' => 'Hutang',
            'is_postable' => true,
            'status' => true,
        ]);

        $equityAccount = $this->createAccount($connection, $equityCategory->id, [
            'code' => '3001',
            'name' => 'Modal',
            'is_postable' => true,
            'status' => true,
        ]);

        return [$assetAccount, $liabilityAccount, $equityAccount];
    }

    private function seedCategory(string $connection, string $type, string $code, string $name, string $reportType, int $sequenceNo): AccountCategory
    {
        return AccountCategory::on($connection)->create([
            'type' => $type,
            'category_code' => $code,
            'category_name' => $name,
            'report_type' => $reportType,
            'sequence_no' => $sequenceNo,
            'status' => true,
        ]);
    }

    private function createAccount(string $connection, string $categoryId, array $attributes): Account
    {
        return Account::on($connection)->create(array_merge([
            'category_id' => $categoryId,
        ], $attributes));
    }

    private function useSingleDatabaseMode(string $connection): void
    {
        $this->createEmptyConnection($connection);
        Config::set('database.default', $connection);
        Config::set('accounting.master_data.use_shared_database', false);
        Config::set('accounting.master_data.connection', 'master');

        DB::purge($connection);
        DB::reconnect($connection);
    }

    private function useTenantWithSharedMasterMode(): void
    {
        $this->createEmptyConnection('tenant');
        $this->createEmptyConnection('master');

        Config::set('database.default', 'tenant');
        Config::set('accounting.master_data.use_shared_database', true);
        Config::set('accounting.master_data.connection', 'master');

        DB::purge('tenant');
        DB::purge('master');
        DB::reconnect('tenant');
        DB::reconnect('master');
    }

    private function configureSqliteConnection(string $name): void
    {
        Config::set("database.connections.{$name}", [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        DB::purge($name);
    }

    private function createEmptyConnection(string $name): void
    {
        $this->configureSqliteConnection($name);
        DB::reconnect($name);
    }

    private function createAllAccountingTables(string $connection): void
    {
        $this->createMasterTables($connection);
        $this->createTransactionTables($connection);
    }

    private function createMasterTables(string $connection): void
    {
        $prefix = config('accounting.table_prefix', 'acc_');

        Schema::connection($connection)->create($prefix.'account_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('parent_id')->nullable();
            $table->enum('type', ['asset', 'liability', 'equity', 'revenue', 'expense']);
            $table->string('category_code', 50)->unique();
            $table->string('category_name', 100);
            $table->string('report_type', 50);
            $table->integer('sequence_no')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::connection($connection)->create($prefix.'accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('category_id');
            $table->string('code', 30)->unique();
            $table->string('name', 200);
            $table->uuid('parent_id')->nullable();
            $table->integer('level')->default(1);
            $table->boolean('is_postable')->default(true);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    private function createTransactionTables(string $connection): void
    {
        $prefix = config('accounting.table_prefix', 'acc_');

        Schema::connection($connection)->create($prefix.'journal_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('journal_no', 100)->unique();
            $table->date('trx_date');
            $table->uuid('service_id')->nullable();
            $table->string('source_type', 100)->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('reference_no', 100)->nullable();
            $table->text('description')->nullable();
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('status', 20)->default('draft');
            $table->uuid('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->uuid('reversal_of_id')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->boolean('is_reversal')->default(false);
            $table->timestamps();
        });

        Schema::connection($connection)->create($prefix.'journal_entry_details', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('journal_entry_id');
            $table->uuid('account_id');
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::connection($connection)->create($prefix.'fiscal_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->integer('year');
            $table->integer('month');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->uuid('closed_by')->nullable();
            $table->timestamps();
        });
    }
}
