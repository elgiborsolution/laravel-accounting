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
        $table = $tablePrefix.'services';

        if (! $this->tableExists($table) || $this->columnExists($table, 'updated_by')) {
            return;
        }

        $this->schema()->table($table, function (Blueprint $blueprint) {
            $blueprint->string('updated_by', 100)->nullable()->after('description');
        });
    }

    public function down()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        $table = $tablePrefix.'services';

        if (! $this->tableExists($table) || ! $this->columnExists($table, 'updated_by')) {
            return;
        }

        $this->schema()->table($table, function (Blueprint $blueprint) {
            $blueprint->dropColumn('updated_by');
        });
    }
};
