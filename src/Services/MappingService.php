<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Models\ServiceAccount;

class MappingService
{
    public function findByKey(string $key)
    {
        return ServiceAccount::query()
            ->where('mapping_key', $key)
            ->active()
            ->first();
    }

    public function getByService($serviceId)
    {
        return ServiceAccount::query()
            ->where('service_id', $serviceId)
            ->active()
            ->orderBy('sequence_no')
            ->get();
    }
}
