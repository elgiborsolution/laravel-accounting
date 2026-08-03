<?php

namespace ESolution\LaravelAccounting\Contracts;

interface AccountingHook
{
    public function beforeHandle(string $action, array $context): array;

    public function afterHandle(string $action, mixed $result, array $context): mixed;
}
