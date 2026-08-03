<?php

namespace ESolution\LaravelAccounting\Support;

use ESolution\LaravelAccounting\Contracts\AccountingHook;

class NullAccountingHook implements AccountingHook
{
    public function beforeHandle(string $action, array $context): array
    {
        return $context;
    }

    public function afterHandle(string $action, mixed $result, array $context): mixed
    {
        return $result;
    }
}
