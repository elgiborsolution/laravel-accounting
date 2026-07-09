<?php

use ESolution\LaravelAccounting\Support\AccountingConnectionResolver;
use ESolution\LaravelAccounting\Traits\HandlesMasterConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    use HandlesMasterConnection;

    public function up()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        $table = $tablePrefix.'report_mappings';
        $resolver = app(AccountingConnectionResolver::class);

        if ($this->tableExists($table)) {
            return;
        }

        $this->schema()->create($table, function (Blueprint $blueprint) use ($tablePrefix, $resolver) {
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
            if ($resolver->shouldCreateCrossConnectionForeignKeys()) {
                $blueprint->foreign('account_id')
                    ->references('id')
                    ->on($tablePrefix.'accounts')
                    ->cascadeOnDelete();
            }
        });
    }

    public function down()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        $this->schema()->dropIfExists($tablePrefix.'report_mappings');
    }
};
