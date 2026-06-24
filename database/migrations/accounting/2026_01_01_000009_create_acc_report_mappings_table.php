<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        Schema::create($tablePrefix.'report_mappings', function (Blueprint $blueprint) use ($tablePrefix) {
            $blueprint->uuid('id')->primary();
            $blueprint->uuid('account_id');
            $blueprint->string('report_type', 50);
            $blueprint->string('report_group', 100);
            $blueprint->string('report_subgroup', 100)->nullable();
            $blueprint->integer('sequence_no')->default(0);
            $blueprint->boolean('is_active')->default(true);
            $blueprint->timestamps();

            $blueprint->index('account_id');
            $blueprint->index('report_type');
        });

        Schema::table($tablePrefix.'report_mappings', function (Blueprint $blueprint) use ($tablePrefix) {
            $blueprint->foreign('account_id')
                ->references('id')
                ->on($tablePrefix.'accounts')
                ->cascadeOnDelete();
        });
    }

    public function down()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        Schema::dropIfExists($tablePrefix.'report_mappings');
    }
};
