<?php

use ESolution\LaravelAccounting\Support\AccountingConnectionResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = app(AccountingConnectionResolver::class)->resolveTransactionDataConnection();
        $prefix = config('accounting.table_prefix', 'acc_');
        Schema::connection($connection)->create($prefix.'closing_cutoffs', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->date('cutoff_date')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
        DB::connection($connection)->table($prefix.'closing_cutoffs')->insert(['id' => 1]);
        Schema::connection($connection)->create($prefix.'closing_cutoff_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('action', 20);
            $table->date('previous_cutoff_date')->nullable();
            $table->date('cutoff_date')->nullable();
            $table->string('actor_id', 100)->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        $connection = app(AccountingConnectionResolver::class)->resolveTransactionDataConnection();
        $prefix = config('accounting.table_prefix', 'acc_');
        Schema::connection($connection)->dropIfExists($prefix.'closing_cutoff_events');
        Schema::connection($connection)->dropIfExists($prefix.'closing_cutoffs');
    }
};
