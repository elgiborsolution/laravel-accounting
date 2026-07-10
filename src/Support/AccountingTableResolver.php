<?php

namespace ESolution\LaravelAccounting\Support;

use Illuminate\Support\Facades\DB;

class AccountingTableResolver
{
    public function tablePrefix(): string
    {
        return config('accounting.table_prefix', 'acc_');
    }

    public function connectionPrefix(?string $connectionName = null): string
    {
        $connection = DB::connection($connectionName);
        $prefix = $connection->getTablePrefix();

        return is_string($prefix) ? $prefix : '';
    }

    public function rawTable(string $baseTable, ?string $connectionName = null): string
    {
        return $this->connectionPrefix($connectionName).$this->tablePrefix().$baseTable;
    }

    public function table(string $baseTable): string
    {
        return $this->tablePrefix().$baseTable;
    }

    public function connectionTable(string $baseTable, ?string $connectionName = null): string
    {
        return $this->rawTable($baseTable, $connectionName);
    }
}
