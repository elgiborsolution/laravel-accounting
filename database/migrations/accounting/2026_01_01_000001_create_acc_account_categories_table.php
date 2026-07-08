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

        if ($this->tableExists($table)) {
            return;
        }

        $this->schema()->create($table, function (Blueprint $blueprint) {
            $blueprint->uuid('id')->primary();
            $blueprint->enum('type', ['asset', 'liability', 'equity', 'revenue', 'expense']);
            $blueprint->string('category_code', 50)->unique();
            $blueprint->string('category_name', 100);
            $blueprint->string('report_type', 50);
            $blueprint->integer('sequence_no')->default(0);
            $blueprint->boolean('status')->default(true);
            $blueprint->timestamps();
        });
    }

    public function down()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        $table = $tablePrefix.'account_categories';

        $this->schema()->dropIfExists($table);
    }
};
