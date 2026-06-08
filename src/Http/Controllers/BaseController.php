<?php

namespace ESolution\LaravelAccounting\Http\Controllers;

use ESolution\LaravelAccounting\Traits\ApiResponse;
use Illuminate\Routing\Controller;

abstract class BaseController extends Controller
{
    protected $cacheKey;

    use ApiResponse;

    protected function initializeTenantIfNeeded($tenantId = null): ?object
    {
        if (! $tenantId) {
            return null;
        }

        // tenancy() helper exists?
        if (! function_exists('tenancy')) {
            return null;
        }

        try {
            $tenantModel = config('tenancy.tenant_model');

            if (! $tenantModel || ! class_exists($tenantModel)) {
                return null;
            }

            $tenant = $tenantModel::find($tenantId);

            if (! $tenant) {
                return null;
            }

            tenancy()->initialize($tenant);

            return $tenant;
        } catch (\Throwable $e) {
            // never throw errors from package-level logic
            report($e);

            return null;
        }
    }

    protected function getCacheTags($tenantId = null): array
    {
        $tags = [$this->cacheKey];

        if ($tenantId) {
            $tags[] = $this->cacheKey.'_tenant_'.$tenantId;
        }

        return $tags;
    }
}
