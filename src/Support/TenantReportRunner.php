<?php

namespace ESolution\LaravelAccounting\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class TenantReportRunner
{
    /**
     * Resolve the entire list before running any report. Tenant lookup follows
     * the primary-key / route-key convention used by GeneralLedgerService.
     */
    public function run(array $tenantIds, callable $report): array
    {
        validator(['tenant_ids' => $tenantIds], [
            'tenant_ids' => ['required', 'array', 'list', 'min:1'],
            'tenant_ids.*' => ['required', function ($attribute, $value, $fail) {
                if (! is_string($value) && ! is_int($value)) {
                    $fail('The '.$attribute.' must be an integer or string.');
                }
            }],
        ])->validate();

        $tenancy = $this->tenancy();
        $tenantModel = config('tenancy.tenant_model');

        if (! is_string($tenantModel) || ! is_subclass_of($tenantModel, Model::class)) {
            throw ValidationException::withMessages([
                'tenant_ids' => ['Tenant reports require a valid tenancy.tenant_model configuration.'],
            ]);
        }

        $tenants = [];
        $errors = [];
        $identifiers = [];

        foreach ($tenantIds as $index => $identifier) {
            $identifier = trim((string) $identifier);
            if (isset($identifiers[$identifier])) {
                continue;
            }
            $identifiers[$identifier] = true;

            $tenant = $tenantModel::query()->find($identifier);
            if (! $tenant) {
                $tenant = $tenantModel::query()
                    ->where((new $tenantModel)->getRouteKeyName(), $identifier)
                    ->first();
            }

            if (! $tenant) {
                $errors['tenant_ids.'.$index] = ['Tenant ['.$identifier.'] could not be resolved.'];
                continue;
            }

            // Also deduplicate aliases resolving to the same tenant record.
            $tenants[(string) $tenant->getKey()] = $tenant;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $previousTenant = ($tenancy->initialized ?? false) ? ($tenancy->tenant ?? null) : null;
        $previousConnection = DB::getDefaultConnection();

        try {
            $results = [];
            foreach ($tenants as $tenant) {
                $tenancy->initialize($tenant);

                if (! ($tenancy->initialized ?? false)
                    || (string) (($tenancy->tenant ?? null)?->getKey()) !== (string) $tenant->getKey()) {
                    throw new RuntimeException('The requested report tenant could not be initialized.');
                }

                $results[] = [
                    'tenant_id' => (string) $tenant->getKey(),
                    'report' => $report(),
                ];
            }

            return $results;
        } finally {
            try {
                if ($previousTenant) {
                    $tenancy->initialize($previousTenant);
                } else {
                    $tenancy->end();
                }
            } finally {
                // Host tenancy teardown may select its central connection;
                // preserve any default-connection override the caller had.
                DB::setDefaultConnection($previousConnection);
            }
        }
    }

    protected function tenancy(): object
    {
        if (! function_exists('tenancy')) {
            throw ValidationException::withMessages([
                'tenant_ids' => ['Tenant reports require a configured tenancy integration.'],
            ]);
        }

        return tenancy();
    }
}
