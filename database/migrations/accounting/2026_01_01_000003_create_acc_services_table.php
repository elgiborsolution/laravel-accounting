<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        Schema::create($tablePrefix.'services', function (Blueprint $blueprint) {
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
        Schema::dropIfExists($tablePrefix.'services');
    }
};
