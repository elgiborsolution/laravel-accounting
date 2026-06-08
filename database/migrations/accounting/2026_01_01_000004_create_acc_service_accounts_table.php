<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        Schema::create($tablePrefix.'service_accounts', function (Blueprint $blueprint) use ($tablePrefix) {
            $blueprint->uuid('id')->primary();
            $blueprint->uuid('service_id');
            $blueprint->string('mapping_key', 150)->unique();
            $blueprint->string('mapping_name', 200);
            $blueprint->enum('position', ['D', 'K']);
            $blueprint->uuid('account_id')->nullable();
            $blueprint->integer('sequence_no')->default(0);
            $blueprint->boolean('is_dynamic')->default(false);
            $blueprint->boolean('is_required')->default(true);
            $blueprint->boolean('status')->default(true);
            $blueprint->timestamps();

            $blueprint->foreign('service_id')->references('id')->on($tablePrefix.'services')->onDelete('cascade');
            $blueprint->foreign('account_id')->references('id')->on($tablePrefix.'accounts')->onDelete('set null');
        });
    }

    public function down()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        Schema::dropIfExists($tablePrefix.'service_accounts');
    }
};
