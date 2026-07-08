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
        $table = $tablePrefix.'account_categories';

        if (! $this->tableExists($table) || $this->columnExists($table, 'parent_id')) {
            return;
        }

        $this->schema()->table($table, function (Blueprint $blueprint) {
            $blueprint->uuid('parent_id')->nullable()->after('id');
            $blueprint->index('parent_id');
        });

        $this->schema()->table($table, function (Blueprint $blueprint) use ($tablePrefix) {
            $blueprint->foreign('parent_id')
                ->references('id')
                ->on($tablePrefix.'account_categories')
                ->restrictOnDelete();
        });
    }

    public function down()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        $table = $tablePrefix.'account_categories';

        if (! $this->tableExists($table) || ! $this->columnExists($table, 'parent_id')) {
            return;
        }

        $this->schema()->table($table, function (Blueprint $blueprint) use ($tablePrefix) {
            $blueprint->dropForeign($tablePrefix.'account_categories_parent_id_foreign');
            $blueprint->dropIndex($tablePrefix.'account_categories_parent_id_index');
            $blueprint->dropColumn('parent_id');
        });
    }
};
