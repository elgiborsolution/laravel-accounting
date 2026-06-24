<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        Schema::create($tablePrefix.'monthly_balances', function (Blueprint $blueprint) use ($tablePrefix) {
            $blueprint->uuid('id')->primary();
            $blueprint->integer('fiscal_year');
            $blueprint->integer('fiscal_month');
            $blueprint->uuid('account_id');
            $blueprint->decimal('opening_balance', 18, 2)->default(0);
            $blueprint->decimal('total_debit', 18, 2)->default(0);
            $blueprint->decimal('total_credit', 18, 2)->default(0);
            $blueprint->decimal('ending_balance', 18, 2)->default(0);
            $blueprint->integer('journal_count')->default(0);
            $blueprint->datetime('closed_at')->nullable();
            $blueprint->uuid('closed_by')->nullable();
            $blueprint->timestamps();

            $blueprint->unique(['fiscal_year', 'fiscal_month', 'account_id'], 'acc_monthly_balances_unique');
            $blueprint->index(['fiscal_year', 'fiscal_month']);
        });

        Schema::table($tablePrefix.'monthly_balances', function (Blueprint $blueprint) use ($tablePrefix) {
            $blueprint->foreign('account_id')
                ->references('id')
                ->on($tablePrefix.'accounts')
                ->cascadeOnDelete();
        });
    }

    public function down()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        Schema::dropIfExists($tablePrefix.'monthly_balances');
    }
};
