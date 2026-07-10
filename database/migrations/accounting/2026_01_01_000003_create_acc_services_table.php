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

        if ($this->tableExists($table)) {
            return;
        }

        $this->schema()->create($table, function (Blueprint $blueprint) {
            $blueprint->uuid('id')->primary();
            $blueprint->string('service_code', 100)->unique();
            $blueprint->string('service_name', 200);
            $blueprint->string('module_name', 100);
            $blueprint->text('description')->nullable();
            $blueprint->boolean('is_active')->default(true);
            $blueprint->timestamps();
        });
    }

    public function down()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        $table = $tablePrefix.'services';

        $this->schema()->dropIfExists($table);
    }
};
