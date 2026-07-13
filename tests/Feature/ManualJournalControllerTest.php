<?php

namespace ESolution\LaravelAccounting\Tests\Feature;

use ESolution\LaravelAccounting\Enums\JournalStatus;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use ESolution\LaravelAccounting\Models\FiscalPeriod;
use ESolution\LaravelAccounting\Models\JournalEntry;
use ESolution\LaravelAccounting\Models\JournalEntryDetail;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ManualJournalControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->configureSqliteConnection('single');
        $this->configureSqliteConnection('tenant');
        $this->configureSqliteConnection('master');
    }

    public function test_it_creates_a_manual_journal_successfully_in_single_database_mode(): void
    {
        $this->useSingleDatabaseMode('single');
        $this->createAllAccountingTables('single');

        [$debitAccount, $creditAccount] = $this->seedPostingAccounts('single');

        $response = $this->postJson('/api/accounting/journals', [
            'trx_date' => '2026-07-13',
            'reference_no' => 'JU-20260713-0001',
            'description' => 'Jurnal penyesuaian akhir bulan',
            'details' => [
                [
                    'account_id' => $debitAccount->id,
                    'type' => 'D',
                    'amount' => 1000000,
                    'description' => 'Kas',
                ],
                [
                    'account_id' => $creditAccount->id,
                    'type' => 'K',
                    'amount' => 1000000,
                    'description' => 'Modal',
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Manual journal created successfully')
            ->assertJsonPath('data.reference_no', 'JU-20260713-0001')
            ->assertJsonPath('data.status', 'posted');

        $journalId = $response->json('data.id');

        $this->assertDatabaseHas('acc_journal_entries', [
            'id' => $journalId,
            'service_id' => null,
            'source_type' => 'MANUAL_JOURNAL',
            'reference_no' => 'JU-20260713-0001',
            'status' => JournalStatus::POSTED->value,
        ], 'single');

        $this->assertDatabaseHas('acc_journal_entry_details', [
            'journal_entry_id' => $journalId,
            'account_id' => $debitAccount->id,
            'debit' => 1000000,
            'credit' => 0,
        ], 'single');

        $this->assertDatabaseHas('acc_journal_entry_details', [
            'journal_entry_id' => $journalId,
            'account_id' => $creditAccount->id,
            'debit' => 0,
            'credit' => 1000000,
        ], 'single');
    }

    public function test_it_rejects_unbalanced_manual_journals(): void
    {
        $this->useSingleDatabaseMode('single');
        $this->createAllAccountingTables('single');

        [$debitAccount, $creditAccount] = $this->seedPostingAccounts('single');

        $response = $this->postJson('/api/accounting/journals', [
            'trx_date' => '2026-07-13',
            'details' => [
                [
                    'account_id' => $debitAccount->id,
                    'type' => 'D',
                    'amount' => 1000000,
                ],
                [
                    'account_id' => $creditAccount->id,
                    'type' => 'K',
                    'amount' => 900000,
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

        $response = $this->postJson('/api/accounting/journals', [
            'trx_date' => '2026-07-13',
            'details' => [
                [
                    'account_id' => '11111111-1111-1111-1111-111111111111',
                    'type' => 'D',
                    'amount' => 1000000,
                ],
                [
                    'account_id' => '22222222-2222-2222-2222-222222222222',
                    'type' => 'K',
                    'amount' => 1000000,
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

        $this->seedCategory('single');
        $debitAccount = $this->createAccount('single', [
            'code' => '1001',
            'name' => 'Kas',
            'is_postable' => true,
            'status' => false,
        ]);
        $creditAccount = $this->createAccount('single', [
            'code' => '3001',
            'name' => 'Modal',
            'is_postable' => true,
            'status' => true,
        ]);

        $response = $this->postJson('/api/accounting/journals', [
            'trx_date' => '2026-07-13',
            'details' => [
                [
                    'account_id' => $debitAccount->id,
                    'type' => 'D',
                    'amount' => 1000000,
                ],
                [
                    'account_id' => $creditAccount->id,
                    'type' => 'K',
                    'amount' => 1000000,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['details.0.account_id']);
        $this->assertSame("Account is inactive: {$debitAccount->id}", $response->json('errors')['details.0.account_id'][0]);
    }

    public function test_it_rejects_non_postable_accounts(): void
    {
        $this->useSingleDatabaseMode('single');
        $this->createAllAccountingTables('single');

        $this->seedCategory('single');
        $debitAccount = $this->createAccount('single', [
            'code' => '1001',
            'name' => 'Kas',
            'is_postable' => false,
            'status' => true,
        ]);
        $creditAccount = $this->createAccount('single', [
            'code' => '3001',
            'name' => 'Modal',
            'is_postable' => true,
            'status' => true,
        ]);

        $response = $this->postJson('/api/accounting/journals', [
            'trx_date' => '2026-07-13',
            'details' => [
                [
                    'account_id' => $debitAccount->id,
                    'type' => 'D',
                    'amount' => 1000000,
                ],
                [
                    'account_id' => $creditAccount->id,
                    'type' => 'K',
                    'amount' => 1000000,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['details.0.account_id']);
        $this->assertSame("Account is not postable: {$debitAccount->id}", $response->json('errors')['details.0.account_id'][0]);
    }

    public function test_it_rejects_closed_fiscal_periods(): void
    {
        $this->useSingleDatabaseMode('single');
        $this->createAllAccountingTables('single');

        [$debitAccount, $creditAccount] = $this->seedPostingAccounts('single');
        FiscalPeriod::create([
            'year' => 2026,
            'month' => 7,
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
            'is_closed' => true,
            'closed_at' => now(),
            'closed_by' => null,
        ]);

        $response = $this->postJson('/api/accounting/journals', [
            'trx_date' => '2026-07-13',
            'details' => [
                [
                    'account_id' => $debitAccount->id,
                    'type' => 'D',
                    'amount' => 1000000,
                ],
                [
                    'account_id' => $creditAccount->id,
                    'type' => 'K',
                    'amount' => 1000000,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['trx_date']);
    }

    public function test_it_supports_multi_tenant_routes_with_shared_master_data(): void
    {
        $this->useTenantWithSharedMasterMode();
        $this->createMasterTables('master');
        $this->createTransactionTables('tenant');

        $this->seedCategory('master');
        $debitAccount = $this->createAccount('master', [
            'code' => '1001',
            'name' => 'Kas',
            'is_postable' => true,
            'status' => true,
        ]);
        $creditAccount = $this->createAccount('master', [
            'code' => '3001',
            'name' => 'Modal',
            'is_postable' => true,
            'status' => true,
        ]);

        $response = $this->postJson('/api/accounting/tenant-a/journals', [
            'trx_date' => '2026-07-13',
            'details' => [
                [
                    'account_id' => $debitAccount->id,
                    'type' => 'D',
                    'amount' => 1000000,
                ],
                [
                    'account_id' => $creditAccount->id,
                    'type' => 'K',
                    'amount' => 1000000,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'posted');

        $journalId = $response->json('data.id');

        $this->assertDatabaseHas('acc_journal_entries', [
            'id' => $journalId,
            'source_type' => 'MANUAL_JOURNAL',
            'status' => JournalStatus::POSTED->value,
        ], 'tenant');

        $this->assertDatabaseHas('acc_journal_entry_details', [
            'journal_entry_id' => $journalId,
            'account_id' => $debitAccount->id,
        ], 'tenant');
    }

    private function seedPostingAccounts(string $connection): array
    {
        $this->seedCategory($connection);

        $debitAccount = $this->createAccount($connection, [
            'code' => '1001',
            'name' => 'Kas',
            'is_postable' => true,
            'status' => true,
        ]);

        $creditAccount = $this->createAccount($connection, [
            'code' => '3001',
            'name' => 'Modal',
            'is_postable' => true,
            'status' => true,
        ]);

        return [$debitAccount, $creditAccount];
    }

    private function seedCategory(string $connection): AccountCategory
    {
        return AccountCategory::on($connection)->create([
            'type' => 'ASSET',
            'category_code' => 'CASH_CASH_EQUIVALENT',
            'category_name' => 'Cash & Cash Equivalent',
            'report_type' => 'BS',
            'sequence_no' => 1,
            'status' => true,
        ]);
    }

    private function createAccount(string $connection, array $attributes): Account
    {
        $category = AccountCategory::on($connection)->first();

        return Account::on($connection)->create(array_merge([
            'category_id' => $category->id,
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
