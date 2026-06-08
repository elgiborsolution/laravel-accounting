<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        Schema::create($tablePrefix.'fiscal_periods', function (Blueprint $blueprint) {
            $blueprint->uuid('id')->primary();
            $blueprint->integer('year');
            $blueprint->integer('month');
            $blueprint->date('start_date');
            $blueprint->date('end_date');
            $blueprint->boolean('is_closed')->default(false);
            $blueprint->datetime('closed_at')->nullable();
            $blueprint->uuid('closed_by')->nullable();
            $blueprint->timestamps();

            $blueprint->unique(['year', 'month']);
        });
    }

    public function down()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        Schema::dropIfExists($tablePrefix.'fiscal_periods');
    }
};
