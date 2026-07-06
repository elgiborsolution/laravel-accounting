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

        Schema::connection($connection)->create($tablePrefix.'accounts', function (Blueprint $blueprint) use ($tablePrefix) {
            $blueprint->uuid('id')->primary();
            $blueprint->uuid('category_id');
            $blueprint->string('code', 30)->unique();
            $blueprint->string('name', 200);
            $blueprint->uuid('parent_id')->nullable();
            $blueprint->integer('level')->default(1);
            $blueprint->boolean('is_postable')->default(true);
            $blueprint->boolean('status')->default(true);
            $blueprint->timestamps();

            $blueprint->index('category_id');
            $blueprint->index('parent_id');

            $blueprint->foreign('category_id')
                ->references('id')
                ->on($tablePrefix.'account_categories')
                ->cascadeOnDelete();

            $blueprint->foreign('parent_id')
                ->references('id')
                ->on($tablePrefix.'accounts')
                ->nullOnDelete();
        });
    }

    public function down()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        $connection = app(AccountingConnectionResolver::class)->resolveMasterDataConnection();

        Schema::connection($connection)->dropIfExists($tablePrefix.'accounts');
    }
};
