<?php

namespace ESolution\LaravelAccounting\Support;

use ESolution\LaravelAccounting\Exceptions\ReferencedAccountingException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use PDOException;
use Throwable;

/**
 * Keeps technical failure details (SQL, bindings, traces) in the application log while callers
 * show readable text. report() logs through the host exception handler with a reference that
 * the readable text repeats.
 */
class AccountingErrorReporter
{
    /** Report the full exception with a new reference and return that reference. */
    public static function report(Throwable $e, string $summary, array $context = []): string
    {
        $reference = (string) Str::uuid();
        report(new ReferencedAccountingException($reference, $summary, $e, $context));

        return $reference;
    }

    public static function databaseException(Throwable $e): QueryException|PDOException|null
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof QueryException || $current instanceof PDOException) {
                return $current;
            }
        }

        return null;
    }

    public static function sqlState(Throwable $e): ?string
    {
        $database = self::databaseException($e);
        $state = is_array($database?->errorInfo ?? null) ? ($database->errorInfo[0] ?? null) : null;
        if ($state === null && $database !== null && preg_match('/SQLSTATE\[(\w+)\]/', $database->getMessage(), $match)) {
            $state = $match[1];
        }

        return $state === null ? null : (string) $state;
    }

    /** Foreign-key violations: PostgreSQL 23503, MySQL/MariaDB 1451/1452, SQLite/SQL Server text. */
    public static function isForeignKeyViolation(Throwable $e): bool
    {
        $database = self::databaseException($e);
        if ($database === null) {
            return false;
        }
        $state = self::sqlState($e);
        $driverCode = is_array($database->errorInfo ?? null) ? ($database->errorInfo[1] ?? null) : null;

        return $state === '23503'
            || ($state === '23000' && (in_array((int) $driverCode, [1451, 1452], true) || stripos($database->getMessage(), 'foreign key') !== false));
    }

    /** Database failures, including messages that embed SQL text. */
    public static function isDatabaseError(Throwable $e): bool
    {
        return self::databaseException($e) !== null || self::looksTechnical($e->getMessage());
    }

    /** The message itself unless it is a database failure, which becomes generic readable text. */
    public static function safeMessage(Throwable $e): string
    {
        return self::isDatabaseError($e) ? self::databaseMessage(self::sqlState($e)) : $e->getMessage();
    }

    /** Sanitize a stored message (for rows written before sanitization existed). */
    public static function sanitize(?string $message): ?string
    {
        if ($message === null || ! self::looksTechnical($message)) {
            return $message;
        }
        $state = preg_match('/SQLSTATE\[(\w+)\]/', $message, $match) ? $match[1] : null;
        $operation = preg_match('/^(\w+): /', $message, $prefix) ? $prefix[1].': ' : '';

        return $operation.self::databaseMessage($state);
    }

    protected static function looksTechnical(string $message): bool
    {
        return preg_match('/SQLSTATE\[|\(Connection: |\bSQL: |Stack trace:/i', $message) === 1;
    }

    protected static function databaseMessage(?string $state): string
    {
        return $state === null
            ? trans('accounting::reporting.database_error')
            : trans('accounting::reporting.database_error_state', ['state' => $state]);
    }
}
