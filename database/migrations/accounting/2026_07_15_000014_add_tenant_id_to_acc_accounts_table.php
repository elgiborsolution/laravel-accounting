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

        if (! $this->tableExists($table) || $this->columnExists($table, 'tenant_id')) {
            return;
        }

        $this->schema()->table($table, function (Blueprint $blueprint) {
            $blueprint->string('tenant_id', 100)->nullable()->after('category_id')->index();
        });
    }

    public function down()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        $table = $tablePrefix.'accounts';

        if (! $this->tableExists($table) || ! $this->columnExists($table, 'tenant_id')) {
            return;
        }

        $this->schema()->table($table, function (Blueprint $blueprint) {
            $blueprint->dropIndex(['tenant_id']);
            $blueprint->dropColumn('tenant_id');
        });
    }
};
