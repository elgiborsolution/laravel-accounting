<?php

namespace ESolution\LaravelAccounting\Models;

use ESolution\LaravelAccounting\Support\AccountingConnectionResolver;

abstract class TransactionDataModel extends AccountingModel
{
    public function getConnectionName()
    {
        $connection = parent::getConnectionName();

        if ($connection !== null && $connection !== '') {
            return $connection;
        }

        return app(AccountingConnectionResolver::class)->resolveTransactionDataConnection();
    }
}
