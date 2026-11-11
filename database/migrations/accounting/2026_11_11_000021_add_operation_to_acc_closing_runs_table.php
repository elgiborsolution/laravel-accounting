<?php

use ESolution\LaravelAccounting\Traits\HandlesCentralMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global monthly reopening (ClosingService::reopenMonthGlobally()) shares the global closing
 * tracking tables on the master connection (accounting.master_data.connection). Adds the run's
 * operation (existing rows become 'close'), the reopen reason and the reopen outcome counts.
 * Like 2026_11_09_000020, it acts only on the run that targets the master connection and is a
 * no-op on tenant runs or without the closing-run table.
 */
return new class extends Migration
{
    use HandlesCentralMigration;

    public function up(): void
    {
        $connection = $this->centralConnection();
        $table = config('accounting.table_prefix', 'acc_').'closing_runs';
        if ($connection === null || ! Schema::connection($connection)->hasTable($table)) {
            return;
        }
        $schema = Schema::connection($connection);
        $columns = [
            'operation' => fn (Blueprint $t) => $t->string('operation', 10)->default('close')->after('trigger'),
            'reason' => fn (Blueprint $t) => $t->text('reason')->nullable()->after('actor_id'),
            'reopened_count' => fn (Blueprint $t) => $t->unsignedInteger('reopened_count')->default(0)->after('failed_count'),
            'already_open_count' => fn (Blueprint $t) => $t->unsignedInteger('already_open_count')->default(0)->after('reopened_count'),
        ];
        foreach ($columns as $column => $define) {
            if (! $schema->hasColumn($table, $column)) {
                $schema->table($table, $define);
            }
        }
    }

    /** Only the run that targets the master connection drops these columns; tenant rollbacks leave them. */
    public function down(): void
    {
        $connection = $this->centralConnection();
        $table = config('accounting.table_prefix', 'acc_').'closing_runs';
        if ($connection === null || ! Schema::connection($connection)->hasTable($table)) {
            return;
        }
        $schema = Schema::connection($connection);
        foreach (['already_open_count', 'reopened_count', 'reason', 'operation'] as $column) {
            if ($schema->hasColumn($table, $column)) {
                $schema->table($table, fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
