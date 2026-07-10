<?php

namespace ESolution\LaravelAccounting\Http\Controllers\Api;

use ESolution\LaravelAccounting\Http\Controllers\BaseController;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\Service;
use ESolution\LaravelAccounting\Models\ServiceAccount;
use ESolution\LaravelAccounting\Repositories\ServiceRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

        return DB::connection((new Service)->getConnectionName())->transaction(function () use ($validated, $tenantId) {
            $service = Service::create([
                'service_code' => $validated['service_code'],
                'service_name' => $validated['service_name'],
                'module_name' => $validated['module_name'],
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'] ?? true,
            ]);

            if (isset($validated['mappings'])) {
                foreach ($validated['mappings'] as $mapping) {
                    ServiceAccount::create($mapping + ['service_id' => $service->id]);
                }
            }

            $service = app(ServiceRepository::class)->loadMappings($service);

            $this->clearCache($tenantId);

            return $this->successResponse('Service created successfully', $service, 201);
        });
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

        return DB::connection($service->getConnectionName())->transaction(function () use ($validated, $service, $tenantId) {
            $service->update([
                'service_code' => $validated['service_code'] ?? $service->service_code,
                'service_name' => $validated['service_name'] ?? $service->service_name,
                'module_name' => $validated['module_name'] ?? $service->module_name,
                'description' => $validated['description'] ?? $service->description,
                'status' => $validated['status'] ?? $service->status,
            ]);

            if (isset($validated['mappings'])) {
                $existingMappingIds = [];
                foreach ($validated['mappings'] as $mappingData) {
                    if (isset($mappingData['id'])) {
                        $mapping = ServiceAccount::findOrFail($mappingData['id']);
                        $mapping->update($mappingData);
                        $existingMappingIds[] = $mapping->id;
                    } else {
                        $newMapping = ServiceAccount::create($mappingData + ['service_id' => $service->id]);
                        $existingMappingIds[] = $newMapping->id;
                    }
                }
                // Delete mappings not in the request
                ServiceAccount::where('service_id', $service->id)
                    ->whereNotIn('id', $existingMappingIds)
                    ->delete();
            }

            $service = app(ServiceRepository::class)->loadMappings($service);

            $this->clearCache($tenantId);

            return $this->successResponse('Service updated successfully', $service);
        });
    }

    public function destroy(Request $request, $tenantId = null, $id = null)
    {
        if ($id === null) {
            $id = $tenantId;
            $tenantId = null;
        }
        $this->initializeTenantIfNeeded($tenantId);

        $service = Service::findOrFail($id);
        $service->delete(); // This should cascade if DB rules allow, or we handle it manually
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
        $service->status = ! $service->status;
        $service->save();
        $this->clearCache($tenantId);

        return $this->successResponse('Service status toggled successfully', $service);
    }

    protected function clearCache($tenantId = null)
    {
        Cache::tags($this->getCacheTags($tenantId))->flush();
    }
}
