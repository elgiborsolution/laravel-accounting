<?php

namespace ESolution\LaravelAccounting\Exceptions;

use Exception;

class AccountingPeriodLockedException extends Exception
{
    public function __construct($message = 'Accounting period is closed and locked for modifications.')
    {
        parent::__construct($message, 423);
    }
}
