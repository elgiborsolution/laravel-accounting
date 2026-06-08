<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        Schema::create($tablePrefix.'journal_entries', function (Blueprint $blueprint) use ($tablePrefix) {
            $blueprint->uuid('id')->primary();
            $blueprint->string('journal_no', 100)->unique();
            $blueprint->date('trx_date');
            $blueprint->uuid('service_id')->nullable();
            $blueprint->string('source_type', 100)->nullable();
            $blueprint->uuid('source_id')->nullable();
            $blueprint->string('reference_no', 100)->nullable();
            $blueprint->text('description')->nullable();
            $blueprint->enum('status', ['draft', 'posted', 'reversed'])->default('draft');
            $blueprint->uuid('posted_by')->nullable();
            $blueprint->datetime('posted_at')->nullable();
            $blueprint->timestamps();

            $blueprint->foreign('service_id')->references('id')->on($tablePrefix.'services')->onDelete('set null');

            $blueprint->index('trx_date');
            $blueprint->index('status');
            $blueprint->index(['source_type', 'source_id']);
        });
    }

    public function down()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        Schema::dropIfExists($tablePrefix.'journal_entries');
    }
};
