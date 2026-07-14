<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        $table = $tablePrefix.'journal_entries';

        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'amount')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->decimal('amount', 18, 2)->default(0)->after('description');
        });
    }

    public function down()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        $table = $tablePrefix.'journal_entries';

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'amount')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropColumn('amount');
        });
    }
};
