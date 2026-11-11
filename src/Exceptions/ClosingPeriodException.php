<?php

namespace ESolution\LaravelAccounting\Exceptions;

use Exception;

/**
 * Monthly closing refused a period. Extends Exception so existing catch (Exception) callers and
 * messages are unchanged; $reason lets callers react without parsing the message.
 */
class ClosingPeriodException extends Exception
{
    public const NOT_FOUND = 'not_found';

    public const ALREADY_CLOSED = 'already_closed';

    public const UNBALANCED = 'unbalanced';

    /** reopenMonth(): the period is open. */
    public const NOT_CLOSED = 'not_closed';

    /** reopenMonth(): a later month is closed; details hold its year and month. */
    public const LATER_MONTH_CLOSED = 'later_month_closed';

    public function __construct(public readonly string $reason, string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
