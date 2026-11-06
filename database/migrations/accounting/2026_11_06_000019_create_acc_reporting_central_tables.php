<?php

use ESolution\LaravelAccounting\Repositories\CentralReportingRepository;
use ESolution\LaravelAccounting\Traits\HandlesCentralMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central reporting tables, owned by the master connection (accounting.master_data.connection).
 * Created only by the run that targets that connection, never by tenant runs, and only when
 * CentralReportingRepository::assertSupported() accepts the configuration (shared master data
 * with its tables on that connection). Otherwise the migration is a no-op, so installs that do
 * not use central reporting migrate unchanged; the sync commands and central reads still reject
 * an unsupported configuration explicitly.
 *
 * Only sync state, tenant journals and details, fiscal period status and closing snapshots are
 * stored here. Accounts, categories and services are not copied: central reports read the
 * existing master tables on this connection, and copied journal details and snapshots keep
 * their central account/service ids. Every copied row keeps its exact
 * source attributes in `payload`; typed columns exist only for filtering and aggregation.
 */
return new class extends Migration
{
    use HandlesCentralMigration;

    public function up(): void
    {
        $connection = $this->centralConnection();
        if ($connection === null || ! app(CentralReportingRepository::class)->supported()) {
            return;
        }
        $prefix = config('accounting.table_prefix', 'acc_').'reporting_';
        $schema = Schema::connection($connection);
        // Re-runs never recreate existing tables.
        $create = function (string $table, Closure $definition) use ($schema, $prefix) {
            if (! $schema->hasTable($prefix.$table)) {
                $schema->create($prefix.$table, $definition);
            }
        };

        $create('sources', function (Blueprint $table) {
            $table->string('tenant_id', 100)->primary();
            $table->string('backfill_status', 20)->default('pending');
            $table->timestamp('backfill_started_at')->nullable();
            $table->timestamp('backfill_completed_at')->nullable();
            $table->string('sync_status', 20)->default('pending');
            $table->timestamp('synced_through')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->unsignedInteger('failures')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('reconcile_status', 20)->default('unknown');
            $table->timestamp('reconciled_at')->nullable();
            $table->text('reconcile_details')->nullable();
            $table->date('cutoff_date')->nullable();
            $table->timestamp('cutoff_updated_at')->nullable();
            $table->boolean('cutoff_available')->default(false);
            $table->string('lock_owner', 64)->nullable();
            $table->timestamp('lock_expires_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        $create('journal_entries', function (Blueprint $table) {
            $table->string('tenant_id', 100);
            $table->string('id', 64);
            $table->date('trx_date')->nullable();
            $table->string('status', 20)->nullable();
            $table->string('service_id', 64)->nullable();
            $table->string('source_type', 100)->nullable();
            $table->longText('payload');
            $table->primary(['tenant_id', 'id']);
            $table->index(['tenant_id', 'trx_date', 'status']);
        });

        $create('journal_entry_details', function (Blueprint $table) {
            $table->string('tenant_id', 100);
            $table->string('id', 64);
            $table->string('journal_entry_id', 64);
            $table->string('account_id', 64)->nullable();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->longText('payload');
            $table->primary(['tenant_id', 'id']);
            $table->index(['tenant_id', 'journal_entry_id']);
            $table->index(['tenant_id', 'account_id']);
        });

        $create('fiscal_periods', function (Blueprint $table) {
            $table->string('tenant_id', 100);
            $table->string('id', 64);
            $table->integer('year');
            $table->integer('month');
            $table->longText('payload');
            $table->primary(['tenant_id', 'id']);
            $table->index(['tenant_id', 'year', 'month']);
        });

        $create('monthly_balances', function (Blueprint $table) {
            $table->string('tenant_id', 100);
            $table->string('id', 64);
            $table->integer('fiscal_year');
            $table->integer('fiscal_month');
            $table->string('account_id', 64);
            $table->decimal('opening_balance', 18, 2)->default(0);
            $table->decimal('total_debit', 18, 2)->default(0);
            $table->decimal('total_credit', 18, 2)->default(0);
            $table->decimal('ending_balance', 18, 2)->default(0);
            $table->integer('journal_count')->default(0);
            $table->longText('payload');
            $table->primary(['tenant_id', 'id']);
            $table->index(['tenant_id', 'fiscal_year', 'fiscal_month']);
            $table->index(['tenant_id', 'account_id']);
        });
    }

    /** Only the run that targets the master connection drops these tables; tenant rollbacks leave them. */
    public function down(): void
    {
        $connection = $this->centralConnection();
        if ($connection === null) {
            return;
        }
        $prefix = config('accounting.table_prefix', 'acc_').'reporting_';
        foreach (['monthly_balances', 'fiscal_periods', 'journal_entry_details', 'journal_entries', 'sources'] as $table) {
            Schema::connection($connection)->dropIfExists($prefix.$table);
        }
    }
};
