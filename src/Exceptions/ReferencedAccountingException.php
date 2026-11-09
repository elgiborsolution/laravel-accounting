<?php

namespace ESolution\LaravelAccounting\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Carries a support reference to the host exception handler: report() logs the original
 * exception (as the previous exception, with SQL and trace) and context() adds the reference,
 * so the readable message shown to users can be matched to the full log entry.
 */
class ReferencedAccountingException extends RuntimeException
{
    public function __construct(
        public readonly string $reference,
        string $summary,
        Throwable $previous,
        protected array $logContext = []
    ) {
        parent::__construct($summary.' [reference '.$reference.']: '.$previous::class.': '.$previous->getMessage(), 0, $previous);
    }

    public function context(): array
    {
        return ['reference' => $this->reference] + $this->logContext;
    }
}
