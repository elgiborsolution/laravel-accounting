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

        if (! $this->tableExists($table) || $this->columnExists($table, 'description')) {
            return;
        }

        $this->schema()->table($table, function (Blueprint $blueprint) {
            $blueprint->text('description')->nullable()->after('name');
        });
    }

    public function down()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        $table = $tablePrefix.'accounts';

        if (! $this->tableExists($table) || ! $this->columnExists($table, 'description')) {
            return;
        }

        $this->schema()->table($table, function (Blueprint $blueprint) {
            $blueprint->dropColumn('description');
        });
    }
};
