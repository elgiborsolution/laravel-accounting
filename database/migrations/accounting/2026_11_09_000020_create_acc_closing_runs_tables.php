<?php

use ESolution\LaravelAccounting\Traits\HandlesCentralMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global monthly-closing tracking (ClosingService::closeMonthGlobally()), owned by the master
 * connection (accounting.master_data.connection). Created only by the run that targets that
 * connection, never by tenant runs; a no-op without a configured master connection. Journals,
 * fiscal periods and snapshots stay in each tenant transaction database.
 */
return new class extends Migration
{
    use HandlesCentralMigration;

    public function up(): void
    {
        $connection = $this->centralConnection();
        if ($connection === null) {
            return;
        }
        $prefix = config('accounting.table_prefix', 'acc_');
        $schema = Schema::connection($connection);

        if (! $schema->hasTable($prefix.'closing_runs')) {
            $schema->create($prefix.'closing_runs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedSmallInteger('year');
                $table->unsignedTinyInteger('month');
                $table->string('trigger', 20);
                $table->string('status', 20);
                $table->string('actor_id', 100)->nullable();
                $table->json('tenant_ids');
                $table->unsignedInteger('tenant_count')->default(0);
                $table->unsignedInteger('closed_count')->default(0);
                $table->unsignedInteger('already_closed_count')->default(0);
                $table->unsignedInteger('not_due_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->index(['year', 'month', 'trigger']);
                $table->index('status');
            });
        }

        if (! $schema->hasTable($prefix.'closing_run_tenants')) {
            $schema->create($prefix.'closing_run_tenants', function (Blueprint $table) use ($prefix) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('run_id');
                $table->string('tenant_id', 100);
                $table->string('status', 20);
                $table->unsignedInteger('attempts')->default(0);
                $table->string('claim_token', 36)->nullable();
                $table->timestamp('claimed_until')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->decimal('total_debit', 18, 2)->nullable();
                $table->decimal('total_credit', 18, 2)->nullable();
                $table->string('error_code', 50)->nullable();
                $table->json('error_params')->nullable();
                $table->string('error_reference', 36)->nullable();
                $table->timestamps();
                $table->unique(['run_id', 'tenant_id']);
                $table->index(['tenant_id', 'status']);
                $table->foreign('run_id')->references('id')->on($prefix.'closing_runs')->cascadeOnDelete();
            });
        }

        // One active worker per tenant and period across every run; an expired lease may be taken over.
        if (! $schema->hasTable($prefix.'closing_run_locks')) {
            $schema->create($prefix.'closing_run_locks', function (Blueprint $table) {
                $table->string('tenant_id', 100);
                $table->unsignedSmallInteger('year');
                $table->unsignedTinyInteger('month');
                $table->unsignedBigInteger('item_id')->nullable();
                $table->string('token', 36)->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->primary(['tenant_id', 'year', 'month']);
            });
        }
    }

    /** Only the run that targets the master connection drops these tables; tenant rollbacks leave them. */
    public function down(): void
    {
        $connection = $this->centralConnection();
        if ($connection === null) {
            return;
        }
        $prefix = config('accounting.table_prefix', 'acc_');
        Schema::connection($connection)->dropIfExists($prefix.'closing_run_locks');
        Schema::connection($connection)->dropIfExists($prefix.'closing_run_tenants');
        Schema::connection($connection)->dropIfExists($prefix.'closing_runs');
    }
};
