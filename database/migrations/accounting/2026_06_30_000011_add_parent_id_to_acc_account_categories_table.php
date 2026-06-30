<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');

        Schema::table($tablePrefix.'account_categories', function (Blueprint $blueprint) use ($tablePrefix) {
            $blueprint->uuid('parent_id')->nullable()->after('id');
            $blueprint->index('parent_id');
        });

        Schema::table($tablePrefix.'account_categories', function (Blueprint $blueprint) use ($tablePrefix) {
            $blueprint->foreign('parent_id')
                ->references('id')
                ->on($tablePrefix.'account_categories')
                ->restrictOnDelete();
        });
    }

    public function down()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');

        Schema::table($tablePrefix.'account_categories', function (Blueprint $blueprint) use ($tablePrefix) {
            $blueprint->dropForeign($tablePrefix.'account_categories_parent_id_foreign');
            $blueprint->dropIndex($tablePrefix.'account_categories_parent_id_index');
            $blueprint->dropColumn('parent_id');
        });
    }
};
