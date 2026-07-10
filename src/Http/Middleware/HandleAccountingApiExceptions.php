<?php

namespace ESolution\LaravelAccounting\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Throwable;
use ESolution\LaravelAccounting\Support\AccountingExceptionResponder;

class HandleAccountingApiExceptions
{
    public function __construct(protected AccountingExceptionResponder $responder)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        try {
            return $next($request);
        } catch (Throwable $e) {
            $response = $this->responder->render($request, $e);

            if ($response) {
                return $response;
            }

            throw $e;
        }
    }
}
