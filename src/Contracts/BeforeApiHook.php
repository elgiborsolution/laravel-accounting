<?php

namespace ESolution\LaravelAccounting\Contracts;

use ESolution\LaravelAccounting\Support\ApiContext;

interface BeforeApiHook
{
    public function handle(ApiContext $context): void;
}
