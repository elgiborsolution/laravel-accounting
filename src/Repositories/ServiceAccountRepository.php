<?php

namespace ESolution\LaravelAccounting\Repositories;

use ESolution\LaravelAccounting\Models\ServiceAccount;
use Illuminate\Support\Collection;

class ServiceAccountRepository
{
    public function findById(string $id): ?ServiceAccount
    {
        return ServiceAccount::query()->find($id);
    }

    public function findByKey(string $key): ?ServiceAccount
    {
        return ServiceAccount::query()
            ->where('mapping_key', $key)
            ->active()
            ->first();
    }

    public function forServiceId(string $serviceId): Collection
    {
        return ServiceAccount::query()
            ->where('service_id', $serviceId)
            ->active()
            ->orderBy('sequence_no')
            ->get();
    }
}
