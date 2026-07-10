<?php

namespace ESolution\LaravelAccounting\Tests\Feature;

use ESolution\LaravelAccounting\Http\Middleware\HandleAccountingApiExceptions;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class AccountingErrorHandlingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->configureSqliteConnection('accounting_test');
        Config::set('database.default', 'accounting_test');
    }

    public function test_query_exception_returns_safe_database_error_response(): void
    {
        Config::set('app.debug', false);
        Log::spy();

        $middleware = app(HandleAccountingApiExceptions::class);
        $request = Request::create('/api/accounting/reports/general-ledger', 'GET');

        $previous = new PDOException('relation "acc_monthly_balances" does not exist', 0);
        $previous->errorInfo = ['42P01', 7, 'relation "acc_monthly_balances" does not exist'];

        $response = $middleware->handle($request, function () use ($previous) {
            throw new QueryException(
                'accounting_test',
                'select * from acc_monthly_balances where account_id = ?',
                ['abc'],
                $previous
            );
        });

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame([
            'success' => false,
            'message' => 'Accounting data is not available. Please verify the accounting database configuration and migrations.',
            'error_code' => 'ACCOUNTING_DATABASE_ERROR',
        ], $response->getData(true));

        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) {
            return $message === 'Accounting package exception'
                && ($context['exception'] ?? null) === 'QueryException'
                && ($context['sql'] ?? null) === 'select * from acc_monthly_balances where account_id = ?'
                && ($context['bindings'] ?? null) === ['abc']
                && ($context['connection'] ?? null) === 'accounting_test'
                && array_key_exists('trace', $context);
        });
    }

    public function test_pdo_exception_in_debug_mode_includes_debug_payload(): void
    {
        Config::set('app.debug', true);
        Log::spy();

        $middleware = app(HandleAccountingApiExceptions::class);
        $request = Request::create('/api/accounting/reports/general-ledger', 'GET');

        $exception = new PDOException('Connection error', 0);
        $exception->errorInfo = ['08006', 0, 'Connection error'];

        $response = $middleware->handle($request, function () use ($exception) {
            throw $exception;
        });

        $this->assertSame(500, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['success']);
        $this->assertSame('Accounting data is not available. Please verify the accounting database configuration and migrations.', $response->getData(true)['message']);
        $this->assertSame('ACCOUNTING_DATABASE_ERROR', $response->getData(true)['error_code']);
        $this->assertSame('PDOException', data_get($response->getData(true), 'debug.exception'));
        $this->assertSame('accounting_test', data_get($response->getData(true), 'debug.connection'));

        Log::shouldHaveReceived('error')->once();
    }

    public function test_fallback_throwable_returns_internal_error_response(): void
    {
        Config::set('app.debug', false);
        Log::spy();

        $middleware = app(HandleAccountingApiExceptions::class);
        $request = Request::create('/api/accounting/reports/general-ledger', 'GET');

        $response = $middleware->handle($request, function () {
            throw new RuntimeException('Unexpected failure');
        });

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame([
            'success' => false,
            'message' => 'Accounting error.',
            'error_code' => 'ACCOUNTING_INTERNAL_ERROR',
        ], $response->getData(true));

        Log::shouldHaveReceived('error')->once();
    }

    public function test_query_exception_renderable_on_accounting_route_uses_safe_response(): void
    {
        Config::set('app.debug', false);
        Log::spy();

        Route::get('/api/accounting/_test/query-exception', function () {
            $previous = new PDOException('relation "acc_monthly_balances" does not exist', 0);
            $previous->errorInfo = ['42P01', 7, 'relation "acc_monthly_balances" does not exist'];

            throw new QueryException(
                'accounting_test',
                'select * from acc_monthly_balances where account_id = ?',
                ['abc'],
                $previous
            );
        });

        $response = $this->getJson('/api/accounting/_test/query-exception');

        $response->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'ACCOUNTING_DATABASE_ERROR')
            ->assertJsonMissingPath('debug');
        $this->assertSame(
            'Accounting data is not available. Please verify the accounting database configuration and migrations.',
            $response->json('message')
        );
    }

    private function configureSqliteConnection(string $name): void
    {
        Config::set("database.connections.{$name}", [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
    }
}
