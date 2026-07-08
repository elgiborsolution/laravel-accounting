<?php

namespace ESolution\LaravelAccounting\Models;

use ESolution\LaravelAccounting\Support\AccountingConnectionResolver;
use Illuminate\Database\Eloquent\Model;

abstract class AccountingModel extends Model
{
    use HasUuid;

    protected string $baseTable = '';

    protected bool $usesSharedMasterConnection = false;

    public function getTable()
    {
        if ($this->table !== null) {
            return $this->table;
        }

        return config('accounting.table_prefix', 'acc_').$this->baseTable;
    }

    public function getConnectionName()
    {
        $connection = parent::getConnectionName();

        if ($connection !== null && $connection !== '') {
            return $connection;
        }

        if (! $this->usesSharedMasterConnection) {
            return null;
        }

        return app(AccountingConnectionResolver::class)->resolveMasterDataConnection();
    }

    public static function validationTable(): string
    {
        // @phpstan-ignore-next-line
        $model = new static;
        $connection = $model->getConnectionName();
        $table = $model->getTable();

        return $connection ? $connection.'.'.$table : $table;
    }
}
