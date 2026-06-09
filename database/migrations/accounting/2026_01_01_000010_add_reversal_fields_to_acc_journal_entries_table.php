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
            $blueprint->uuid('reversal_of_id')->nullable()->after('posted_at');
            $blueprint->text('reversal_reason')->nullable()->after('reversal_of_id');
            $blueprint->datetime('reversed_at')->nullable()->after('reversal_reason');
            $blueprint->boolean('is_reversal')->default(false)->after('reversed_at');

            $blueprint->foreign('reversal_of_id')
                ->references('id')
                ->on($tablePrefix.'journal_entries')
                ->onDelete('set null');

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
