<?php

namespace ESolution\LaravelAccounting\Http\Controllers;

use ESolution\LaravelAccounting\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

abstract class BaseController extends Controller
{
    protected $cacheKey;

    use ApiResponse;

    protected function initializeTenantIfNeeded($tenantId = null): ?object
    {
        $tenantIdentifier = $this->resolveTenantIdentifier($tenantId);

        if (! $tenantIdentifier) {
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

            $tenant = $this->findTenantModel($tenantModel, $tenantIdentifier);

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

    protected function resolveTenantIdentifier($tenantId = null): ?string
    {
        if ($tenantId !== null && $tenantId !== '') {
            return (string) $tenantId;
        }

        $request = request();
        if (! $request instanceof Request) {
            return null;
        }

        $headerTenant = $request->header('X-Tenant');
        if ($headerTenant !== null && $headerTenant !== '') {
            return (string) $headerTenant;
        }

        $routeTenant = $request->route('tenantId');
        if ($routeTenant !== null && $routeTenant !== '') {
            return (string) $routeTenant;
        }

        return null;
    }

    protected function findTenantModel(string $tenantModel, string $tenantIdentifier): ?Model
    {
        $instance = new $tenantModel;

        if ($tenantIdentifier !== '') {
            $tenant = $tenantModel::query()->find($tenantIdentifier);
            if ($tenant) {
                return $tenant;
            }
        }

        if (method_exists($instance, 'getRouteKeyName')) {
            $routeKey = $instance->getRouteKeyName();
            $tenant = $tenantModel::query()->where($routeKey, $tenantIdentifier)->first();
            if ($tenant) {
                return $tenant;
            }
        }

        return null;
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
