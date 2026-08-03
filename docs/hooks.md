# Hook System

The package provides a reusable hook lifecycle so host applications can run custom logic before and after package execution without modifying package source code.

The current implementation is used by:

- `services.store`
- `services.update`

The same mechanism is designed to be reused by additional package services and APIs over time.

## Lifecycle

```text
beforeHandle()
  ↓
Business Logic
  ↓
afterHandle()
```

Sequence overview:

```text
Host Application
  ↓
Package Controller or Internal Service Call
  ↓
AccountingHookManager
  ↓
Configured before hook
  ↓
Main package business logic
  ↓
Configured after hook
  ↓
Return result
```

Sequence diagram:

```text
Caller              HookManager           Before Hook          Package Logic          After Hook
  |                     |                      |                     |                    |
  | execute()           |                      |                     |                    |
  |-------------------->|                      |                     |                    |
  |                     | handle(context)      |                     |                    |
  |                     |--------------------->|                     |                    |
  |                     |<---------------------|                     |                    |
  |                     | run business logic   |                     |                    |
  |                     |------------------------------------------->|                    |
  |                     |<-------------------------------------------| result             |
  |                     | handle(context,result)                                          |
  |                     |--------------------------------------------------------------->|
  |                     |<---------------------------------------------------------------|
  |<--------------------| final result         |                     |                    |
```

## Available Hooks

### Configured hooks

- `before`
  - One optional class
  - Runs before package business logic
  - Receives `ApiContext`
  - Returns `void`
- `after`
  - One optional class
  - Runs after package business logic succeeds
  - Receives `ApiContext` and current result
  - Returns the final result

## Execution Order

Execution order is:

1. Configured `before` hook
2. Main business logic
3. Configured `after` hook

If a hook is disabled with `null`, that step is skipped.

## Contracts

### Before hook

Interface:

- `ESolution\LaravelAccounting\Contracts\BeforeApiHook`

Method:

```php
public function handle(ApiContext $context): void;
```

### After hook

Interface:

- `ESolution\LaravelAccounting\Contracts\AfterApiHook`

Method:

```php
public function handle(ApiContext $context, mixed $result): mixed;
```

## `ApiContext`

Class:

- `ESolution\LaravelAccounting\Support\ApiContext`

Main data available inside hooks:

- `action()` returns the logical package action such as `services.store`
- `request()` returns the current `Illuminate\Http\Request` when available
- `payload()` returns the current payload array
- `setPayload(array $payload)` replaces the payload
- `mergePayload(array $payload)` merges additional payload values
- `header(string $key, mixed $default = null)` reads request headers
- `user()` returns the authenticated user if the host application provides one
- `get(string $key, mixed $default = null)` reads extra context values
- `set(string $key, mixed $value)` stores extra context values for downstream logic
- `all()` returns the raw context array

## Expected Return Values

### Before hook

- Must not return anything
- May mutate the payload through `ApiContext`
- May throw exceptions to stop execution

### After hook

- Must return a result
- May return the original result unchanged
- May return a modified result

### Legacy hooks

- `beforeHandle()` must return the updated context array
- `afterHandle()` must return the final result

## Configuration

Register hooks in `config/accounting.php`:

```php
return [

    /*
    |--------------------------------------------------------------------------
    | API Hooks
    |--------------------------------------------------------------------------
    |
    | Hooks allow you to execute custom logic before and after every package
    | API or service execution.
    |
    | Set to null to disable a hook.
    |
    */

    'hooks' => [

        'before' => App\Accounting\Hooks\BeforeApiHook::class,

        'after' => App\Accounting\Hooks\AfterApiHook::class,

    ],

];
```

Disable hooks:

```php
'hooks' => [
    'before' => null,
    'after' => null,
],
```

## Available Actions And Routes

The hook system uses logical action names. Each action is more stable than the raw route name and is the recommended value to branch on inside hook code.

Current action map:

| Action | HTTP Route | Trigger Source |
|---|---|---|
| `services.store` | `POST /api/accounting/services` | Service create API |
| `services.update` | `PUT /api/accounting/services/{id}` | Service update API |

Notes:

- The same action name is also used when the package service is called internally without HTTP.
- For internal calls, `request()` may be `null` unless the caller explicitly passes a request object.
- Future package modules can add more actions while keeping the same hook contract.

## Example Hook Classes

Package example stubs are available in:

- [stubs/hooks/BeforeApiHook.php.stub](/C:/laragon/www/package-custom/laravel-accounting/stubs/hooks/BeforeApiHook.php.stub)
- [stubs/hooks/AfterApiHook.php.stub](/C:/laragon/www/package-custom/laravel-accounting/stubs/hooks/AfterApiHook.php.stub)

### Before hook example

```php
<?php

namespace App\Accounting\Hooks;

use ESolution\LaravelAccounting\Contracts\BeforeApiHook as BeforeApiHookContract;
use ESolution\LaravelAccounting\Support\ApiContext;
use Illuminate\Validation\ValidationException;

class BeforeApiHook implements BeforeApiHookContract
{
    /**
     * Executed before the package processes the request.
     *
     * Typical use cases:
     * - inject created_by / updated_by
     * - tenant validation
     * - custom authorization
     * - request normalization
     * - auditing
     */
    public function handle(ApiContext $context): void
    {
        $payload = $context->payload();
        $tenantId = $context->header('X-Tenant');

        if (! array_key_exists('updated_by', $payload) && $context->user()) {
            $payload['updated_by'] = (string) $context->user()->getAuthIdentifier();
        }

        if ($tenantId) {
            $payload['tenant_id'] = $tenantId;
        }

        $context->setPayload($payload);
    }
}
```

### After hook example

```php
<?php

namespace App\Accounting\Hooks;

use ESolution\LaravelAccounting\Contracts\AfterApiHook as AfterApiHookContract;
use ESolution\LaravelAccounting\Support\ApiContext;
use Illuminate\Support\Facades\Log;

class AfterApiHook implements AfterApiHookContract
{
    /**
     * Executed after the package successfully processes the request.
     *
     * Typical use cases:
     * - audit log
     * - activity log
     * - notification
     * - synchronization
     * - cache invalidation
     */
    public function handle(ApiContext $context, mixed $result): mixed
    {
        Log::info('Accounting package action finished', [
            'action' => $context->action(),
            'tenant_id' => $context->header('X-Tenant'),
            'user_id' => $context->user()?->getAuthIdentifier(),
        ]);

        return $result;
    }
}
```

## Developer Experience Examples

### Registering custom hooks

```php
'hooks' => [
    'before' => App\Accounting\Hooks\BeforeApiHook::class,
    'after' => App\Accounting\Hooks\AfterApiHook::class,
],
```

### Disabling hooks

```php
'hooks' => [
    'before' => null,
    'after' => null,
],
```

### Accessing the current request

```php
$request = $context->request();
$method = $request?->method();
$url = $request?->fullUrl();
```

### Accessing authenticated user information

```php
$user = $context->user();
$userId = $user?->getAuthIdentifier();
$userEmail = $user?->email;
```

### Reading request headers

```php
$tenantId = $context->header('X-Tenant');
```

### Modifying request payload

```php
$payload = $context->payload();
$payload['updated_by'] = '947';
$payload['description'] = trim((string) ($payload['description'] ?? ''));

$context->setPayload($payload);
```

### Modifying API response or service result

```php
public function handle(ApiContext $context, mixed $result): mixed
{
    if (is_object($result) && method_exists($result, 'setAttribute')) {
        $result->setAttribute('hook_processed', true);
        $result->setAttribute('hook_action', $context->action());
    }

    return $result;
}
```

### Throwing validation or business exceptions

```php
use Illuminate\Validation\ValidationException;

if (! $context->header('X-Tenant')) {
    throw ValidationException::withMessages([
        'X-Tenant' => ['Header X-Tenant is required.'],
    ]);
}
```

### Logging

```php
use Illuminate\Support\Facades\Log;

Log::info('Accounting hook executed', [
    'action' => $context->action(),
    'tenant_id' => $context->header('X-Tenant'),
    'payload' => $context->payload(),
]);
```

### Multi-tenant use case

```php
use Illuminate\Validation\ValidationException;

public function handle(ApiContext $context): void
{
    $tenantId = $context->header('X-Tenant');

    if (! $tenantId) {
        throw ValidationException::withMessages([
            'X-Tenant' => ['Header X-Tenant is required.'],
        ]);
    }
}
```

### Internal service call use case

Hooks also run when package services call the hook manager directly, not only through HTTP endpoints.

```php
use ESolution\LaravelAccounting\Services\ServiceManagementService;
use Illuminate\Http\Request;

$service = app(ServiceManagementService::class)->create([
    'service_code' => 'CUSTOM_SERVICE',
    'service_name' => 'Custom Service',
    'module_name' => 'FIN',
], Request::create('/internal/services', 'POST'));
```

## Best Practices

- Keep hooks focused and deterministic.
- Prefer payload normalization in `before` and side effects in `after`.
- Throw explicit validation or domain exceptions when rejecting a request.
- Avoid heavy synchronous I/O in hooks unless it is required for correctness.
- Treat hooks as application-specific extension points, not as replacements for core package business rules.
- When modifying results in `after`, preserve the original structure expected by callers.
- For multi-tenant hosts, validate tenant identity as early as possible in `before`.
- Use structured logging so hook activity is traceable in production.
