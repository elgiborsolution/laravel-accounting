<?php

namespace ESolution\LaravelAccounting\Tests\Feature;

use ESolution\LaravelAccounting\Enums\JournalStatus;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use ESolution\LaravelAccounting\Models\JournalEntry;
use ESolution\LaravelAccounting\Models\JournalEntryDetail;
use ESolution\LaravelAccounting\Models\MonthlyBalance;
use ESolution\LaravelAccounting\Models\ReportMapping;
use ESolution\LaravelAccounting\Models\Service;
use ESolution\LaravelAccounting\Models\ServiceAccount;
use ESolution\LaravelAccounting\Repositories\JournalRepository;
use ESolution\LaravelAccounting\Repositories\ServiceRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AccountingConnectionModesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->configureSqliteConnection('tenant');
        $this->configureSqliteConnection('master');
    }

    public function test_single_database_uses_default_connection_for_all_tables(): void
    {
        $this->useSingleDatabaseMode('single');
        $this->createAllAccountingTables('single');

        $category = AccountCategory::create([
            'type' => 'ASSET',
            'category_code' => 'CASH_CASH_EQUIVALENT',
            'category_name' => 'Cash & Cash Equivalent',
            'report_type' => 'BS',
            'sequence_no' => 1,
            'status' => true,
        ]);

        $account = Account::create([
            'category_id' => $category->id,
            'code' => '1000',
            'name' => 'Cash',
            'is_postable' => true,
            'status' => true,
        ]);

        $service = Service::create([
            'service_code' => 'FINANCE',
            'service_name' => 'Finance',
            'module_name' => 'FIN',
            'description' => null,
            'is_active' => true,
        ]);

        ServiceAccount::create([
            'service_id' => $service->id,
            'mapping_key' => 'cash',
            'mapping_name' => 'Cash',
            'position' => 'D',
            'account_id' => $account->id,
            'sequence_no' => 1,
            'is_dynamic' => false,
            'is_required' => true,
            'status' => true,
        ]);

        $journal = JournalEntry::create([
            'journal_no' => 'JV/2026/07/0001',
            'trx_date' => now(),
            'service_id' => $service->id,
            'description' => 'Single DB journal',
            'status' => JournalStatus::DRAFT,
        ]);

        JournalEntryDetail::create([
            'journal_entry_id' => $journal->id,
            'account_id' => $account->id,
            'debit' => 100,
            'credit' => 0,
            'description' => 'Cash receipt',
        ]);

        $this->assertSame('acc_accounts', Account::validationTable());
        $this->assertSame('single.acc_journal_entries', JournalEntry::validationTable());
        $this->assertTrue(DB::connection('single')->table('acc_accounts')->where('code', '1000')->exists());
        $this->assertTrue(DB::connection('single')->table('acc_journal_entries')->where('journal_no', 'JV/2026/07/0001')->exists());

        $service = app(ServiceRepository::class)->loadMappings($service);
        $this->assertSame('1000', $service->mappings->first()->account->code);
        $journal = app(JournalRepository::class)->attachViewRelations($journal);
        $this->assertSame('CASH_CASH_EQUIVALENT', $journal->details->first()->account->category_id ? AccountCategory::where('id', $journal->details->first()->account->category_id)->value('category_code') : null);
    }

    public function test_shared_master_mode_uses_master_connection_for_master_tables(): void
    {
        $this->useTenantWithSharedMasterMode();
        $this->createMasterTables('master');
        $this->createTransactionTables('tenant');

        $category = AccountCategory::create([
            'type' => 'ASSET',
            'category_code' => 'CASH_CASH_EQUIVALENT',
            'category_name' => 'Cash & Cash Equivalent',
            'report_type' => 'BS',
            'sequence_no' => 1,
            'status' => true,
        ]);

        $account = Account::create([
            'category_id' => $category->id,
            'code' => '1000',
            'name' => 'Cash',
            'is_postable' => true,
            'status' => true,
        ]);

        $service = Service::create([
            'service_code' => 'FINANCE',
            'service_name' => 'Finance',
            'module_name' => 'FIN',
            'description' => null,
            'is_active' => true,
        ]);

        $mapping = ServiceAccount::create([
            'service_id' => $service->id,
            'mapping_key' => 'cash',
            'mapping_name' => 'Cash',
            'position' => 'D',
            'account_id' => $account->id,
            'sequence_no' => 1,
            'is_dynamic' => false,
            'is_required' => true,
            'status' => true,
        ]);

        $journal = JournalEntry::create([
            'journal_no' => 'JV/2026/07/0001',
            'trx_date' => now(),
            'service_id' => $service->id,
            'description' => 'Shared master journal',
            'status' => JournalStatus::DRAFT,
        ]);

        JournalEntryDetail::create([
            'journal_entry_id' => $journal->id,
            'account_id' => $account->id,
            'debit' => 100,
            'credit' => 0,
            'description' => 'Cash receipt',
        ]);

        $this->assertSame('master.acc_accounts', Account::validationTable());
        $this->assertSame('tenant.acc_journal_entries', JournalEntry::validationTable());
        $this->assertTrue(DB::connection('master')->table('acc_accounts')->where('code', '1000')->exists());
        $this->assertTrue(DB::connection('tenant')->table('acc_journal_entries')->where('journal_no', 'JV/2026/07/0001')->exists());

        $service = app(ServiceRepository::class)->loadMappings($service);
        $this->assertSame($mapping->id, $service->mappings->first()->id);
        $this->assertSame('1000', $service->mappings->first()->account->code);

        $journal = app(JournalRepository::class)->attachViewRelations($journal);
        $this->assertSame('1000', $journal->details->first()->account->code);
        $this->assertSame('CASH_CASH_EQUIVALENT', AccountCategory::where('id', $journal->details->first()->account->category_id)->value('category_code'));
    }

    public function test_journal_show_endpoint_uses_transaction_connection_in_shared_master_mode(): void
    {
        $this->useTenantWithSharedMasterMode();
        $this->createMasterTables('master');
        $this->createTransactionTables('tenant');

        $category = AccountCategory::create([
            'type' => 'ASSET',
            'category_code' => 'CASH_CASH_EQUIVALENT',
            'category_name' => 'Cash & Cash Equivalent',
            'report_type' => 'BS',
            'sequence_no' => 1,
            'status' => true,
        ]);

        $account = Account::create([
            'category_id' => $category->id,
            'code' => '1000',
            'name' => 'Cash',
            'is_postable' => true,
            'status' => true,
        ]);

        $service = Service::create([
            'service_code' => 'FINANCE',
            'service_name' => 'Finance',
            'module_name' => 'FIN',
            'description' => null,
            'is_active' => true,
        ]);

        $journal = JournalEntry::create([
            'journal_no' => 'JV/2026/07/0002',
            'trx_date' => now(),
            'service_id' => $service->id,
            'description' => 'Journal show endpoint',
            'status' => JournalStatus::DRAFT,
        ]);

        JournalEntryDetail::create([
            'journal_entry_id' => $journal->id,
            'account_id' => $account->id,
            'debit' => 250,
            'credit' => 0,
            'description' => 'Cash receipt',
        ]);

        $response = $this->getJson("/api/accounting/journals/{$journal->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $journal->id)
            ->assertJsonPath('data.details.0.account.code', '1000');
    }

    public function test_general_ledger_endpoint_uses_transaction_connection_in_shared_master_mode(): void
    {
        $this->useTenantWithSharedMasterMode();
        $this->createMasterTables('master');
        $this->createTransactionTables('tenant');

        $category = AccountCategory::create([
            'type' => 'ASSET',
            'category_code' => 'CASH_CASH_EQUIVALENT',
            'category_name' => 'Cash & Cash Equivalent',
            'report_type' => 'BS',
            'sequence_no' => 1,
            'status' => true,
        ]);

        $account = Account::create([
            'category_id' => $category->id,
            'code' => '1000',
            'name' => 'Cash',
            'is_postable' => true,
            'status' => true,
        ]);

        MonthlyBalance::create([
            'fiscal_year' => 2026,
            'fiscal_month' => 7,
            'account_id' => $account->id,
            'opening_balance' => 100,
            'total_debit' => 0,
            'total_credit' => 0,
            'ending_balance' => 100,
            'journal_count' => 0,
            'closed_at' => null,
            'closed_by' => null,
        ]);

        $journal = JournalEntry::create([
            'journal_no' => 'JV/2026/07/0003',
            'trx_date' => '2026-07-10',
            'service_id' => null,
            'description' => 'General ledger test',
            'status' => JournalStatus::POSTED,
        ]);

        JournalEntryDetail::create([
            'journal_entry_id' => $journal->id,
            'account_id' => $account->id,
            'debit' => 50,
            'credit' => 0,
            'description' => 'Cash receipt',
        ]);

        $response = $this->getJson("/api/accounting/reports/general-ledger?account_id={$account->id}&start_date=2026-07-15&end_date=2026-07-31");

        $response->assertOk()
            ->assertJsonPath('data.account.id', $account->id)
            ->assertJsonPath('data.opening_balance', 150);
    }

    public function test_multi_tenant_without_shared_master_keeps_master_data_on_tenant_connection(): void
    {
        $this->useTenantModeWithoutSharedMaster();
        $this->createAllAccountingTables('tenant');

        $category = AccountCategory::create([
            'type' => 'ASSET',
            'category_code' => 'CASH_CASH_EQUIVALENT',
            'category_name' => 'Cash & Cash Equivalent',
            'report_type' => 'BS',
            'sequence_no' => 1,
            'status' => true,
        ]);

        $account = Account::create([
            'category_id' => $category->id,
            'code' => '1000',
            'name' => 'Cash',
            'is_postable' => true,
            'status' => true,
        ]);

        $service = Service::create([
            'service_code' => 'FINANCE',
            'service_name' => 'Finance',
            'module_name' => 'FIN',
            'description' => null,
            'is_active' => true,
        ]);

        $journal = JournalEntry::create([
            'journal_no' => 'JV/2026/07/0001',
            'trx_date' => now(),
            'service_id' => $service->id,
            'description' => 'Tenant journal',
            'status' => JournalStatus::DRAFT,
        ]);

        JournalEntryDetail::create([
            'journal_entry_id' => $journal->id,
            'account_id' => $account->id,
            'debit' => 100,
            'credit' => 0,
            'description' => 'Cash receipt',
        ]);

        $this->assertSame('acc_accounts', Account::validationTable());
        $this->assertSame('tenant.acc_journal_entries', JournalEntry::validationTable());
        $this->assertTrue(DB::connection('tenant')->table('acc_accounts')->where('code', '1000')->exists());
        $this->assertTrue(DB::connection('tenant')->table('acc_journal_entries')->where('journal_no', 'JV/2026/07/0001')->exists());
    }

    public function test_master_migration_uses_master_connection_when_shared_database_is_enabled(): void
    {
        $this->useTenantWithSharedMasterMode();
        $this->createEmptyConnection('master');
        $this->createEmptyConnection('tenant');

        $migration = require dirname(__DIR__, 2).'/database/migrations/accounting/2026_01_01_000001_create_acc_account_categories_table.php';
        $migration->up();

        $this->assertTrue(Schema::connection('master')->hasTable('acc_account_categories'));
        $this->assertFalse(Schema::connection('tenant')->hasTable('acc_account_categories'));

        $migration->down();
    }

    public function test_report_mapping_migration_uses_master_connection_when_shared_database_is_enabled(): void
    {
        $this->useTenantWithSharedMasterMode();
        $this->createEmptyConnection('master');
        $this->createEmptyConnection('tenant');

        $migration = require dirname(__DIR__, 2).'/database/migrations/accounting/2026_01_01_000009_create_acc_report_mappings_table.php';
        $migration->up();

        $this->assertSame('master.acc_report_mappings', ReportMapping::validationTable());
        $this->assertTrue(Schema::connection('master')->hasTable('acc_report_mappings'));
        $this->assertFalse(Schema::connection('tenant')->hasTable('acc_report_mappings'));

        $migration->down();
    }

    public function test_master_migrations_skip_if_already_exist(): void
    {
        $this->useTenantWithSharedMasterMode();
        $this->createEmptyConnection('master');
        $this->createEmptyConnection('tenant');

        $migration = require dirname(__DIR__, 2).'/database/migrations/accounting/2026_01_01_000001_create_acc_account_categories_table.php';
        $migration->up();

        $this->assertTrue(Schema::connection('master')->hasTable('acc_account_categories'));

        try {
            $migration->up();
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            $this->fail('Migration failed when running a second time on existing table: '.$e->getMessage());
        }
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

    private function useTenantModeWithoutSharedMaster(): void
    {
        $this->createEmptyConnection('tenant');
        Config::set('database.default', 'tenant');
        Config::set('accounting.master_data.use_shared_database', false);
        Config::set('accounting.master_data.connection', 'master');

        DB::purge('tenant');
        DB::reconnect('tenant');
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

        Schema::connection($connection)->create($prefix.'services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('service_code', 100)->unique();
            $table->string('service_name', 200);
            $table->string('module_name', 100);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::connection($connection)->create($prefix.'service_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('service_id');
            $table->string('mapping_key', 150)->unique();
            $table->string('mapping_name', 200);
            $table->enum('position', ['D', 'K']);
            $table->uuid('account_id')->nullable();
            $table->integer('sequence_no')->default(0);
            $table->boolean('is_dynamic')->default(false);
            $table->boolean('is_required')->default(true);
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

        Schema::connection($connection)->create($prefix.'monthly_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->integer('fiscal_year');
            $table->integer('fiscal_month');
            $table->uuid('account_id');
            $table->decimal('opening_balance', 18, 2)->default(0);
            $table->decimal('total_debit', 18, 2)->default(0);
            $table->decimal('total_credit', 18, 2)->default(0);
            $table->decimal('ending_balance', 18, 2)->default(0);
            $table->integer('journal_count')->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->uuid('closed_by')->nullable();
            $table->timestamps();
        });

        Schema::connection($connection)->create($prefix.'report_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->string('report_type', 50);
            $table->string('report_group', 100)->nullable();
            $table->string('report_subgroup', 100)->nullable();
            $table->integer('sequence_no')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }
}
