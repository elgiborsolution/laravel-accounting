<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        Schema::create($tablePrefix.'account_categories', function (Blueprint $blueprint) {
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
        Schema::dropIfExists($tablePrefix.'account_categories');
    }
};
