<?php

namespace ESolution\LaravelAccounting\Facades;

use Illuminate\Support\Facades\Facade;

class Accounting extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'laravel-accounting';
    }
}
