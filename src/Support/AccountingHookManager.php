<?php

namespace ESolution\LaravelAccounting\Support;

use ESolution\LaravelAccounting\Contracts\AfterApiHook;
use ESolution\LaravelAccounting\Contracts\BeforeApiHook;
use InvalidArgumentException;

class AccountingHookManager
{
    public function execute(string $action, array $context, callable $handler): mixed
    {
        $apiContext = ApiContext::fromArray($action, $context);
        $beforeHook = $this->resolveBeforeHook();
        $afterHook = $this->resolveAfterHook();

        if ($beforeHook) {
            $beforeHook->handle($apiContext);
        }

        $result = $handler($apiContext);

        if ($afterHook) {
            $result = $afterHook->handle($apiContext, $result);
        }

        return $result;
    }

    protected function resolveBeforeHook(): ?BeforeApiHook
    {
        $hookClass = config('accounting.hooks.before');

        if ($hookClass === null) {
            return null;
        }

        $hook = app($hookClass);

        if (! $hook instanceof BeforeApiHook) {
            throw new InvalidArgumentException("Configured before hook [{$hookClass}] must implement ".BeforeApiHook::class);
        }

        return $hook;
    }

    protected function resolveAfterHook(): ?AfterApiHook
    {
        $hookClass = config('accounting.hooks.after');

        if ($hookClass === null) {
            return null;
        }

        $hook = app($hookClass);

        if (! $hook instanceof AfterApiHook) {
            throw new InvalidArgumentException("Configured after hook [{$hookClass}] must implement ".AfterApiHook::class);
        }

        return $hook;
    }
}
