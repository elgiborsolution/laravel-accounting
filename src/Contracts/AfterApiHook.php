<?php

namespace ESolution\LaravelAccounting\Contracts;

use ESolution\LaravelAccounting\Support\ApiContext;

interface AfterApiHook
{
    public function handle(ApiContext $context, mixed $result): mixed;
}
