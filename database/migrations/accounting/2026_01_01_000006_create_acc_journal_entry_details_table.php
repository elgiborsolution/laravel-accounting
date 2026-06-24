<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        Schema::create($tablePrefix.'journal_entry_details', function (Blueprint $blueprint) use ($tablePrefix) {
            $blueprint->uuid('id')->primary();
            $blueprint->uuid('journal_entry_id');
            $blueprint->uuid('account_id');
            $blueprint->decimal('debit', 18, 2)->default(0);
            $blueprint->decimal('credit', 18, 2)->default(0);
            $blueprint->text('description')->nullable();
            $blueprint->timestamps();

            $blueprint->index('journal_entry_id');
            $blueprint->index('account_id');
        });

        Schema::table($tablePrefix.'journal_entry_details', function (Blueprint $blueprint) use ($tablePrefix) {
            $blueprint->foreign('journal_entry_id')
                ->references('id')
                ->on($tablePrefix.'journal_entries')
                ->cascadeOnDelete();

            $blueprint->foreign('account_id')
                ->references('id')
                ->on($tablePrefix.'accounts')
                ->cascadeOnDelete();
        });
    }

    public function down()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        Schema::dropIfExists($tablePrefix.'journal_entry_details');
    }
};
