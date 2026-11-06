<?php

use ESolution\LaravelAccounting\Support\AccountingConnectionResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned reporting outbox. Rows identify changed items only; the central
 * sync re-reads their current state and acknowledges rows after its central commit.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = app(AccountingConnectionResolver::class)->resolveTransactionDataConnection();
        $table = config('accounting.table_prefix', 'acc_').'reporting_change_log';
        if (Schema::connection($connection)->hasTable($table)) {
            return;
        }

        Schema::connection($connection)->create($table, function (Blueprint $blueprint) {
            $blueprint->bigIncrements('id');
            $blueprint->string('aggregate_type', 30);
            $blueprint->string('aggregate_key', 100);
            $blueprint->string('operation', 30);
            $blueprint->timestamp('created_at')->nullable();
            $blueprint->timestamp('acknowledged_at')->nullable();
            $blueprint->index(['acknowledged_at', 'id']);
        });
    }

    public function down(): void
    {
        $connection = app(AccountingConnectionResolver::class)->resolveTransactionDataConnection();
        Schema::connection($connection)->dropIfExists(config('accounting.table_prefix', 'acc_').'reporting_change_log');
    }
};
