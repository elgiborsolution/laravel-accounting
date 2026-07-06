<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ESolution\LaravelAccounting\Support\AccountingConnectionResolver;

return new class extends Migration
{
    public function up()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        $connection = app(AccountingConnectionResolver::class)->resolveMasterDataConnection();

        Schema::connection($connection)->create($tablePrefix.'service_accounts', function (Blueprint $blueprint) use ($tablePrefix) {
            $blueprint->uuid('id')->primary();
            $blueprint->uuid('service_id');
            $blueprint->string('mapping_key', 150)->unique();
            $blueprint->string('mapping_name', 200);
            $blueprint->enum('position', ['D', 'K']);
            $blueprint->uuid('account_id')->nullable();
            $blueprint->integer('sequence_no')->default(0);
            $blueprint->boolean('is_dynamic')->default(false);
            $blueprint->boolean('is_required')->default(true);
            $blueprint->boolean('status')->default(true);
            $blueprint->timestamps();

            $blueprint->index('service_id');
            $blueprint->index('account_id');

            $blueprint->foreign('service_id')
                ->references('id')
                ->on($tablePrefix.'services')
                ->cascadeOnDelete();

            $blueprint->foreign('account_id')
                ->references('id')
                ->on($tablePrefix.'accounts')
                ->nullOnDelete();
        });
    }

    public function down()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        $connection = app(AccountingConnectionResolver::class)->resolveMasterDataConnection();

        Schema::connection($connection)->dropIfExists($tablePrefix.'service_accounts');
    }
};
