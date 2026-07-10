<?php

namespace ESolution\LaravelAccounting\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AccountingExceptionResponder
{
    public function render(Request $request, Throwable $e): ?JsonResponse
    {
        if ($this->shouldPassThrough($e) || ! $this->isAccountingRequest($request)) {
            return null;
        }

        return $this->toJsonResponse($request, $e);
    }

    public function shouldPassThrough(Throwable $e): bool
    {
        return $e instanceof ValidationException
            || $e instanceof AuthenticationException
            || $e instanceof AuthorizationException
            || $e instanceof ModelNotFoundException
            || $e instanceof HttpExceptionInterface;
    }

    public function isAccountingRequest(Request $request): bool
    {
        $prefix = trim((string) config('accounting.route.prefix', 'api/accounting'), '/');

        return $prefix !== '' && str_starts_with(trim($request->path(), '/'), $prefix);
    }

    protected function toJsonResponse(Request $request, Throwable $e): JsonResponse
    {
        $isDatabaseException = $e instanceof QueryException || $e instanceof PDOException;
        $errorCode = $isDatabaseException ? 'ACCOUNTING_DATABASE_ERROR' : 'ACCOUNTING_INTERNAL_ERROR';
        $message = $isDatabaseException
            ? 'Accounting data is not available. Please verify the accounting database configuration and migrations.'
            : 'Accounting error.';

        $context = $this->buildLogContext($e);

        Log::error('Accounting package exception', $context + [
            'request_method' => $request->method(),
            'request_path' => $request->path(),
            'request_url' => $request->fullUrl(),
        ]);

        $payload = [
            'success' => false,
            'message' => $message,
            'error_code' => $errorCode,
        ];

        if (config('app.debug')) {
            $payload['debug'] = $this->buildDebugContext($e, $context);
        }

        return response()->json($payload, 500);
    }

    protected function buildLogContext(Throwable $e): array
    {
        $context = [
            'exception' => class_basename($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ];

        if ($e instanceof QueryException) {
            $context['sql'] = $e->getSql();
            $context['bindings'] = $this->normalizeBindings($e->getBindings());
            $context['connection'] = $e->getConnectionName();
            $context['driver'] = $this->resolveDriver($e->getConnectionName());
            $context['database'] = $this->resolveDatabaseName($e->getConnectionName());
            $context['sql_state'] = $this->resolveSqlState($e);
        } elseif ($e instanceof PDOException) {
            $context['sql'] = null;
            $context['bindings'] = [];
            $context['connection'] = DB::getDefaultConnection();
            $context['driver'] = $this->resolveDriver(DB::getDefaultConnection());
            $context['database'] = $this->resolveDatabaseName(DB::getDefaultConnection());
            $context['sql_state'] = $this->resolveSqlState($e);
        }

        return $context;
    }

    protected function buildDebugContext(Throwable $e, array $context): array
    {
        return [
            'connection' => $context['connection'] ?? DB::getDefaultConnection(),
            'database' => $context['database'] ?? null,
            'driver' => $context['driver'] ?? null,
            'sql_state' => $context['sql_state'] ?? null,
            'exception' => $context['exception'],
            'sql' => $context['sql'] ?? null,
        ];
    }

    protected function resolveDriver(?string $connectionName): ?string
    {
        if (! $connectionName) {
            return null;
        }

        try {
            return DB::connection($connectionName)->getDriverName();
        } catch (Throwable) {
            return null;
        }
    }

    protected function resolveDatabaseName(?string $connectionName): ?string
    {
        if (! $connectionName) {
            return null;
        }

        try {
            return DB::connection($connectionName)->getDatabaseName();
        } catch (Throwable) {
            return null;
        }
    }

    protected function resolveSqlState(Throwable $e): ?string
    {
        if ($e instanceof QueryException && is_array($e->errorInfo ?? null)) {
            return $e->errorInfo[0] ?? null;
        }

        if ($e instanceof PDOException && is_array($e->errorInfo ?? null)) {
            return $e->errorInfo[0] ?? null;
        }

        return null;
    }

    protected function normalizeBindings(array $bindings): array
    {
        return array_map(function ($binding) {
            if ($binding instanceof \DateTimeInterface) {
                return $binding->format(DATE_ATOM);
            }

            if (is_bool($binding)) {
                return $binding ? 1 : 0;
            }

            if (is_object($binding) && method_exists($binding, '__toString')) {
                return (string) $binding;
            }

            return $binding;
        }, $bindings);
    }
}
