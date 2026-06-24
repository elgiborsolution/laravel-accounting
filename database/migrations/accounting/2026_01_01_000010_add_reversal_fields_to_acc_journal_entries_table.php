<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');

        Schema::table($tablePrefix.'journal_entries', function (Blueprint $blueprint) use ($tablePrefix) {
            $blueprint->uuid('reversal_of_id')->nullable();
            $blueprint->text('reversal_reason')->nullable();
            $blueprint->datetime('reversed_at')->nullable();
            $blueprint->boolean('is_reversal')->default(false);

            $blueprint->foreign('reversal_of_id')
                ->references('id')
                ->on($tablePrefix.'journal_entries')
                ->nullOnDelete();

            $blueprint->index('reversal_of_id');
            $blueprint->index('is_reversal');
        });
    }

    public function down()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');

        Schema::table($tablePrefix.'journal_entries', function (Blueprint $blueprint) use ($tablePrefix) {
            $blueprint->dropForeign(['reversal_of_id']);
            $blueprint->dropIndex(['reversal_of_id']);
            $blueprint->dropIndex(['is_reversal']);
            $blueprint->dropColumn(['reversal_of_id', 'reversal_reason', 'reversed_at', 'is_reversal']);
        });
    }
};
