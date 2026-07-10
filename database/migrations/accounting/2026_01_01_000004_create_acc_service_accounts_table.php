<?php

use ESolution\LaravelAccounting\Traits\HandlesMasterConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    use HandlesMasterConnection;

    public function up()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        $table = $tablePrefix.'service_accounts';

        if ($this->tableExists($table)) {
            return;
        }

        $this->schema()->create($table, function (Blueprint $blueprint) use ($tablePrefix) {
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

            $blueprint->index('service_id');
            $blueprint->index('account_id');

            $blueprint->foreign('service_id')
                ->references('id')
                ->on($tablePrefix.'services')
                ->cascadeOnDelete();

            $blueprint->foreign('account_id')
                ->references('id')
                ->on($tablePrefix.'accounts')
                ->nullOnDelete();
        });
    }

    public function down()
    {
        $tablePrefix = config('accounting.table_prefix', 'acc_');
        $table = $tablePrefix.'service_accounts';

        $this->schema()->dropIfExists($table);
    }
};
