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
        $this->validateTenantList($tenantIds);
        $tenancy = $this->tenancy();
        $tenants = $this->resolveValidatedTenants($tenantIds);

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

    /**
     * Report entry points: accounting.reporting.read_source 'live' (default) delegates to run();
     * 'central' validates and resolves the same list (canonical ids, aliases deduplicated),
     * then reads central copies without initializing tenancy or using tenant connections.
     */
    public function runReports(array $tenantIds, callable $report): array
    {
        if ($this->readSource() === 'live') {
            return $this->run($tenantIds, $report);
        }

        return app(CentralReportContext::class)->run($this->resolveTenants($tenantIds), $report);
    }

    /**
     * The configured tenant-list report source. A missing key or reporting block (older published
     * configs), null or an empty value means 'live'; values are trimmed and case-insensitive.
     * Anything else is rejected, never treated as live.
     */
    public function readSource(): string
    {
        $source = config('accounting.reporting.read_source');
        if (is_string($source)) {
            $source = strtolower(trim($source));
        }
        if ($source === null || $source === '') {
            return 'live';
        }
        if (! in_array($source, ['live', 'central'], true)) {
            throw new RuntimeException('accounting.reporting.read_source must be live or central.');
        }

        return $source;
    }

    /**
     * Validate and resolve a tenant list without initializing tenancy.
     *
     * @return array<string, Model> canonical tenant id => tenant record, in first-seen order
     */
    public function resolveTenants(array $tenantIds): array
    {
        $this->validateTenantList($tenantIds);

        return $this->resolveValidatedTenants($tenantIds);
    }

    protected function validateTenantList(array $tenantIds): void
    {
        validator(['tenant_ids' => $tenantIds], [
            'tenant_ids' => ['required', 'array', 'list', 'min:1'],
            'tenant_ids.*' => ['required', function ($attribute, $value, $fail) {
                if (! is_string($value) && ! is_int($value)) {
                    $fail('The '.$attribute.' must be an integer or string.');
                }
            }],
        ])->validate();
    }

    /**
     * Every tenant of the configured tenancy model, as canonical (primary key) ids ordered by key,
     * without initializing tenancy.
     *
     * @return list<string>
     */
    public function allTenantIds(): array
    {
        $tenantModel = $this->tenantModel();
        $key = (new $tenantModel)->getKeyName();

        return $tenantModel::query()->orderBy($key)->pluck($key)->map(fn ($id) => (string) $id)->values()->all();
    }

    /** @return class-string<Model> */
    protected function tenantModel(): string
    {
        $tenantModel = config('tenancy.tenant_model');

        if (! is_string($tenantModel) || ! is_subclass_of($tenantModel, Model::class)) {
            throw ValidationException::withMessages([
                'tenant_ids' => ['Tenant reports require a valid tenancy.tenant_model configuration.'],
            ]);
        }

        return $tenantModel;
    }

    protected function resolveValidatedTenants(array $tenantIds): array
    {
        $tenantModel = $this->tenantModel();

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

        return $tenants;
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
