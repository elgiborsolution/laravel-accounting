<?php

namespace ESolution\LaravelAccounting\Traits;

use ESolution\LaravelAccounting\Support\AccountingConnectionResolver;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

trait HandlesMasterConnection
{
    /**
     * Get the target connection name for master data.
     */
    protected function getMasterConnection(): ?string
    {
        return app(AccountingConnectionResolver::class)->resolveMasterDataConnection();
    }

    /**
     * Get the schema builder instance for the target connection.
     */
    protected function schema(): Builder
    {
        return Schema::connection($this->getMasterConnection());
    }

    /**
     * Check if a table exists on the target connection.
     */
    protected function tableExists(string $table): bool
    {
        return $this->schema()->hasTable($table);
    }

    /**
     * Check if a column exists on a table on the target connection.
     */
    protected function columnExists(string $table, string $column): bool
    {
        return $this->schema()->hasColumn($table, $column);
    }
}
