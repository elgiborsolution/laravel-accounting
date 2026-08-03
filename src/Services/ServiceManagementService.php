<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Models\Service;
use ESolution\LaravelAccounting\Models\ServiceAccount;
use ESolution\LaravelAccounting\Repositories\ServiceRepository;
use ESolution\LaravelAccounting\Support\AccountingHookManager;
use ESolution\LaravelAccounting\Support\ApiContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServiceManagementService
{
    public function __construct(
        protected ServiceRepository $services,
        protected AccountingHookManager $hooks
    ) {}

    public function create(array $payload, ?Request $request = null): Service
    {
        return $this->hooks->execute('services.store', [
            'request' => $request,
            'payload' => $payload,
        ], function (ApiContext $context) {
            $payload = $context->payload();

            return DB::connection((new Service)->getConnectionName())->transaction(function () use ($payload) {
                $service = Service::create([
                    'service_code' => $payload['service_code'],
                    'service_name' => $payload['service_name'],
                    'module_name' => $payload['module_name'],
                    'description' => $payload['description'] ?? null,
                    'status' => $payload['status'] ?? true,
                    'updated_by' => $this->normalizeUpdatedBy($payload['updated_by'] ?? null),
                ]);

                foreach ($payload['mappings'] ?? [] as $mapping) {
                    ServiceAccount::create($mapping + ['service_id' => $service->id]);
                }

                return $this->services->loadMappings($service);
            });
        });
    }

    public function update(Service $service, array $payload, ?Request $request = null): Service
    {
        return $this->hooks->execute('services.update', [
            'request' => $request,
            'payload' => $payload,
            'service' => $service,
        ], function (ApiContext $context) {
            /** @var Service $service */
            $service = $context->get('service');
            $payload = $context->payload();

            return DB::connection($service->getConnectionName())->transaction(function () use ($service, $payload) {
                $service->update([
                    'service_code' => $payload['service_code'] ?? $service->service_code,
                    'service_name' => $payload['service_name'] ?? $service->service_name,
                    'module_name' => $payload['module_name'] ?? $service->module_name,
                    'description' => array_key_exists('description', $payload) ? $payload['description'] : $service->description,
                    'status' => $payload['status'] ?? $service->status,
                    'updated_by' => array_key_exists('updated_by', $payload)
                        ? $this->normalizeUpdatedBy($payload['updated_by'])
                        : $service->updated_by,
                ]);

                if (isset($payload['mappings'])) {
                    $existingMappingIds = [];

                    foreach ($payload['mappings'] as $mappingData) {
                        if (isset($mappingData['id'])) {
                            $mapping = ServiceAccount::findOrFail($mappingData['id']);
                            $mapping->update($mappingData);
                            $existingMappingIds[] = $mapping->id;
                        } else {
                            $newMapping = ServiceAccount::create($mappingData + ['service_id' => $service->id]);
                            $existingMappingIds[] = $newMapping->id;
                        }
                    }

                    ServiceAccount::where('service_id', $service->id)
                        ->whereNotIn('id', $existingMappingIds)
                        ->delete();
                }

                return $this->services->loadMappings($service->fresh());
            });
        });
    }

    protected function normalizeUpdatedBy(int|string|null $updatedBy): ?string
    {
        if ($updatedBy === null) {
            return null;
        }

        $value = trim((string) $updatedBy);

        return $value === '' ? null : $value;
    }
}
