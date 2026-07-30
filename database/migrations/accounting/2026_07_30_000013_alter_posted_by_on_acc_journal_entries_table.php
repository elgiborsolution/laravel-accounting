<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        $table = $tablePrefix.'journal_entries';

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'posted_by')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->string('posted_by', 100)->nullable()->change();
        });
    }

    public function down()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        $table = $tablePrefix.'journal_entries';

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'posted_by')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->uuid('posted_by')->nullable()->change();
        });
    }
};
