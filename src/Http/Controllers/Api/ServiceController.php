<?php

namespace ESolution\LaravelAccounting\Http\Controllers\Api;

use ESolution\LaravelAccounting\Http\Controllers\BaseController;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\Service;
use ESolution\LaravelAccounting\Models\ServiceAccount;
use ESolution\LaravelAccounting\Repositories\ServiceRepository;
use ESolution\LaravelAccounting\Services\ServiceManagementService;
use ESolution\LaravelAccounting\Support\ApiContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class ServiceController extends BaseController
{
    protected $cacheKey = 'acc_services';

    public function index(Request $request, $tenantId = null)
    {
        $this->initializeTenantIfNeeded($tenantId);

        $services = Cache::tags($this->getCacheTags($tenantId))->rememberForever('index_all', function () {
            return app(ServiceRepository::class)->allWithMappings();
        });

        return $this->successResponse('Services retrieved successfully', $services);
    }

    public function store(Request $request, $tenantId = null)
    {
        $this->initializeTenantIfNeeded($tenantId);

        $validated = $request->validate([
            'service_code' => ['required', 'string', 'max:100', Rule::unique(Service::validationTable(), 'service_code')],
            'service_name' => 'required|string|max:200',
            'module_name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'updated_by' => ['nullable', function ($attribute, $value, $fail) {
                if (! is_int($value) && ! is_string($value)) {
                    $fail('The '.$attribute.' must be an integer or string.');
                }
            }],
            'status' => 'nullable|boolean',
            'mappings' => 'nullable|array',
            'mappings.*.mapping_key' => 'required|string|max:150',
            'mappings.*.mapping_name' => 'required|string|max:200',
            'mappings.*.position' => 'required|in:D,K',
            'mappings.*.account_id' => ['nullable', Rule::exists(Account::validationTable(), 'id')],
            'mappings.*.sequence_no' => 'nullable|integer',
            'mappings.*.is_dynamic' => 'nullable|boolean',
            'mappings.*.is_required' => 'nullable|boolean',
        ]);

        $service = app(ServiceManagementService::class)->create($validated, $request);
        $this->clearCache($tenantId);

        return $this->successResponse('Service created successfully', $service, 201);
    }

    public function show(Request $request, $tenantId = null, $id = null)
    {
        if ($id === null) {
            $id = $tenantId;
            $tenantId = null;
        }
        $this->initializeTenantIfNeeded($tenantId);

        $service = Cache::tags($this->getCacheTags($tenantId))->rememberForever('show_'.$id, function () use ($id) {
            $svc = Service::findOrFail($id);

            return app(ServiceRepository::class)->loadMappings($svc);
        });

        return $this->successResponse('Service retrieved successfully', $service);
    }

    public function update(Request $request, $tenantId = null, $id = null)
    {
        if ($id === null) {
            $id = $tenantId;
            $tenantId = null;
        }
        $this->initializeTenantIfNeeded($tenantId);

        $service = Service::findOrFail($id);

        $validated = $request->validate([
            'service_code' => ['nullable', 'string', 'max:100', Rule::unique(Service::validationTable(), 'service_code')->ignore($id)],
            'service_name' => 'nullable|string|max:200',
            'module_name' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'updated_by' => ['nullable', function ($attribute, $value, $fail) {
                if (! is_int($value) && ! is_string($value)) {
                    $fail('The '.$attribute.' must be an integer or string.');
                }
            }],
            'status' => 'nullable|boolean',
            'mappings' => 'nullable|array',
            'mappings.*.id' => ['nullable', Rule::exists(ServiceAccount::validationTable(), 'id')],
            'mappings.*.mapping_key' => 'required|string|max:150',
            'mappings.*.mapping_name' => 'required|string|max:200',
            'mappings.*.position' => 'required|in:D,K',
            'mappings.*.account_id' => ['nullable', Rule::exists(Account::validationTable(), 'id')],
            'mappings.*.sequence_no' => 'nullable|integer',
            'mappings.*.is_dynamic' => 'nullable|boolean',
            'mappings.*.is_required' => 'nullable|boolean',
            'mappings.*.status' => 'nullable|boolean',
        ]);

        $service = app(ServiceManagementService::class)->update($service, $validated, $request);
        $this->clearCache($tenantId);

        return $this->successResponse('Service updated successfully', $service);
    }

    public function destroy(Request $request, $tenantId = null, $id = null)
    {
        if ($id === null) {
            $id = $tenantId;
            $tenantId = null;
        }
        $this->initializeTenantIfNeeded($tenantId);

        $service = Service::findOrFail($id);
        $this->executeMutationHook('services.destroy', $request, [], function (ApiContext $context) {
            /** @var Service $service */
            $service = $context->get('service');
            $service->delete();

            return null;
        }, [
            'service' => $service,
        ]);
        $this->clearCache($tenantId);

        return $this->successResponse('Service and its mappings deleted successfully');
    }

    public function toggleStatus(Request $request, $tenantId = null, $id = null)
    {
        if ($id === null) {
            $id = $tenantId;
            $tenantId = null;
        }
        $this->initializeTenantIfNeeded($tenantId);

        $service = Service::findOrFail($id);
        $service = $this->executeMutationHook('services.toggle-status', $request, [
            'status' => ! $service->status,
        ], function (ApiContext $context) {
            /** @var Service $service */
            $service = $context->get('service');
            $service->status = (bool) ($context->payload()['status'] ?? ! $service->status);
            $service->save();

            return $service;
        }, [
            'service' => $service,
        ]);
        $this->clearCache($tenantId);

        return $this->successResponse('Service status toggled successfully', $service);
    }

    protected function clearCache($tenantId = null)
    {
        Cache::tags($this->getCacheTags($tenantId))->flush();
    }
}
