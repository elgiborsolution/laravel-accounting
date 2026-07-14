<?php

use ESolution\LaravelAccounting\Traits\HandlesMasterConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    use HandlesMasterConnection;

    public function up()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        $table = $tablePrefix.'accounts';

        if ($this->tableExists($table)) {
            return;
        }

        $this->schema()->create($table, function (Blueprint $blueprint) use ($tablePrefix) {
            $blueprint->uuid('id')->primary();
            $blueprint->uuid('category_id');
            $blueprint->string('code', 30)->unique();
            $blueprint->string('name', 200);
            $blueprint->text('description')->nullable();
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
        });

        $this->schema()->table($table, function (Blueprint $blueprint) use ($tablePrefix) {
            $blueprint->foreign('parent_id')
                ->references('id')
                ->on($tablePrefix.'accounts')
                ->nullOnDelete();
        });
    }

    public function down()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        $table = $tablePrefix.'accounts';

        if ($this->tableExists($table) && $this->columnExists($table, 'parent_id')) {
            $this->schema()->table($table, function (Blueprint $blueprint) use ($tablePrefix) {
                $blueprint->dropForeign($tablePrefix.'accounts_parent_id_foreign');
            });
        }

        $this->schema()->dropIfExists($table);
    }
};
