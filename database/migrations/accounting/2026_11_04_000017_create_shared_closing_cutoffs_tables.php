<?php

use ESolution\LaravelAccounting\Traits\HandlesCentralMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shared closing cutoff state (closing.cutoff_mode = shared), owned by the master connection
 * (accounting.master_data.connection). Created only by the run that targets that connection,
 * never by tenant runs; a no-op without a configured master connection. The tables are inert
 * while cutoff_mode is per_tenant, so enabling shared mode later needs no migration.
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

        if (! $schema->hasTable($prefix.'shared_closing_cutoffs')) {
            $schema->create($prefix.'shared_closing_cutoffs', function (Blueprint $table) {
                $table->unsignedTinyInteger('id')->primary();
                $table->date('cutoff_date')->nullable();
                $table->json('tenant_ids')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }
        DB::connection($connection)->table($prefix.'shared_closing_cutoffs')->insertOrIgnore(['id' => 1]);

        if (! $schema->hasTable($prefix.'shared_closing_cutoff_events')) {
            $schema->create($prefix.'shared_closing_cutoff_events', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('action', 20);
                $table->date('previous_cutoff_date')->nullable();
                $table->date('cutoff_date')->nullable();
                $table->json('tenant_ids');
                $table->string('actor_id', 100)->nullable();
                $table->text('reason')->nullable();
                $table->timestamp('created_at');
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
        Schema::connection($connection)->dropIfExists($prefix.'shared_closing_cutoff_events');
        Schema::connection($connection)->dropIfExists($prefix.'shared_closing_cutoffs');
    }
};
