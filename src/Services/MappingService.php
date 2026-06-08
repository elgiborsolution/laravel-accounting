<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Models\ServiceAccount;

class MappingService
{
    public function findByKey(string $key)
    {
        return ServiceAccount::where('mapping_key', $key)->where('is_active', true)->first();
    }

    public function getByService($serviceId)
    {
        return ServiceAccount::where('service_id', $serviceId)->where('is_active', true)->orderBy('sequence_no')->get();
    }
}
