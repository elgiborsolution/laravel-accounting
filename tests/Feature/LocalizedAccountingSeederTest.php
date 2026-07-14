<?php

namespace ESolution\LaravelAccounting\Tests\Feature;

use ESolution\LaravelAccounting\Database\Seeders\AccountingSeeder;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use ESolution\LaravelAccounting\Models\ReportMapping;
use ESolution\LaravelAccounting\Models\Service;
use ESolution\LaravelAccounting\Models\ServiceAccount;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LocalizedAccountingSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->configureSqliteConnection('testbench');
    }

    public function test_default_language_uses_indonesian_master_data(): void
    {
        Config::set('accounting.default_language', 'id');

        $this->createMasterTables('testbench');
        $seeder = app(AccountingSeeder::class);
        $seeder->setContainer(app());
        $seeder->run();

        $this->assertSame('Aset', AccountCategory::where('category_code', 'ASSET')->value('category_name'));
        $this->assertSame('Kas', Account::where('code', '1000')->value('name'));
        $this->assertSame('Penjualan Tunai', Service::where('service_code', 'SALES_CASH')->value('service_name'));
        $this->assertSame('Penjualan Tunai - Kas/Bank', ServiceAccount::where('mapping_key', 'sales_cash_cash_d')->value('mapping_name'));
        $this->assertSame('Penjualan Tunai PPN 11%', Service::where('service_code', 'SALES_CASH_VAT')->value('service_name'));
        $this->assertSame('Penjualan Kredit PPN 11%', Service::where('service_code', 'SALES_CREDIT_VAT')->value('service_name'));
        $this->assertSame('Pembayaran PPN', Service::where('service_code', 'VAT_PAYMENT')->value('service_name'));
        $this->assertSame('Penjualan Tunai PPN 11% - Harga Pokok Penjualan', ServiceAccount::where('mapping_key', 'sales_cash_vat_cogs_d')->value('mapping_name'));
        $this->assertSame('Penjualan Kredit PPN 11% - Harga Pokok Penjualan', ServiceAccount::where('mapping_key', 'sales_credit_vat_cogs_d')->value('mapping_name'));
        $cashAccountId = Account::where('code', '1000')->value('id');
        $this->assertSame('Aset', ReportMapping::where('account_id', $cashAccountId)->value('report_group'));
    }

    public function test_configured_language_uses_english_master_data(): void
    {
        Config::set('accounting.default_language', 'en');

        $this->createMasterTables('testbench');
        $seeder = app(AccountingSeeder::class);
        $seeder->setContainer(app());
        $seeder->run();

        $this->assertSame('Asset', AccountCategory::where('category_code', 'ASSET')->value('category_name'));
        $this->assertSame('Cash', Account::where('code', '1000')->value('name'));
        $this->assertSame('Cash Sales', Service::where('service_code', 'SALES_CASH')->value('service_name'));
        $this->assertSame('Cash Sales - Cash/Bank', ServiceAccount::where('mapping_key', 'sales_cash_cash_d')->value('mapping_name'));
        $this->assertSame('Cash Sales with VAT 11%', Service::where('service_code', 'SALES_CASH_VAT')->value('service_name'));
        $this->assertSame('Credit Sales with VAT 11%', Service::where('service_code', 'SALES_CREDIT_VAT')->value('service_name'));
        $this->assertSame('VAT Payment', Service::where('service_code', 'VAT_PAYMENT')->value('service_name'));
        $this->assertSame('Cash Sales with VAT 11% - Cost Of Goods Sold', ServiceAccount::where('mapping_key', 'sales_cash_vat_cogs_d')->value('mapping_name'));
        $this->assertSame('Credit Sales with VAT 11% - Cost Of Goods Sold', ServiceAccount::where('mapping_key', 'sales_credit_vat_cogs_d')->value('mapping_name'));
        $cashAccountId = Account::where('code', '1000')->value('id');
        $this->assertSame('Asset', ReportMapping::where('account_id', $cashAccountId)->value('report_group'));
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
        DB::reconnect($name);
    }

    private function createMasterTables(string $connection): void
    {
        $prefix = config('accounting.table_prefix', 'acc_');

        Schema::connection($connection)->dropIfExists($prefix.'report_mappings');
        Schema::connection($connection)->dropIfExists($prefix.'service_accounts');
        Schema::connection($connection)->dropIfExists($prefix.'services');
        Schema::connection($connection)->dropIfExists($prefix.'accounts');
        Schema::connection($connection)->dropIfExists($prefix.'account_categories');

        Schema::connection($connection)->create($prefix.'account_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('parent_id')->nullable();
            $table->string('type', 20);
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
            $table->text('description')->nullable();
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
