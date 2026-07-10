<?php

namespace ESolution\LaravelAccounting\Models;

use Illuminate\Database\Eloquent\Model;

abstract class AccountingModel extends Model
{
    use HasUuid;

    protected string $baseTable = '';

    public function getTable()
    {
        if ($this->table !== null) {
            return $this->table;
        }

        return config('accounting.table_prefix', 'acc_').$this->baseTable;
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
