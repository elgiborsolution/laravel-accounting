<?php

namespace ESolution\LaravelAccounting\Repositories;

use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\Service;
use Illuminate\Support\Collection;

class ServiceRepository
{
    public function all(): Collection
    {
        return Service::query()->get();
    }

    public function findById(string $id): ?Service
    {
        return Service::query()->find($id);
    }

    public function findByCode(string $serviceCode): ?Service
    {
        return Service::query()
            ->where('service_code', $serviceCode)
            ->first();
    }

    public function loadMappings(Service $service, ?Collection $accounts = null): Service
    {
        $mappings = app(ServiceAccountRepository::class)->forServiceId($service->id);
        $accountRepository = app(AccountRepository::class);
        $accountsById = ($accounts ?? $accountRepository->findManyByIds($mappings->pluck('account_id')->filter()->all()))->keyBy('id');

        $mappings = $mappings->map(function ($mapping) use ($accountsById) {
            $account = $mapping->account_id ? $accountsById->get($mapping->account_id) : null;

            if ($account) {
                $mapping->setRelation('account', $account);
            }

            return $mapping;
        });

        $service->setRelation('mappings', $mappings);

        return $service;
    }

    public function allWithMappings(): Collection
    {
        return $this->all()->map(fn (Service $service) => $this->loadMappings($service));
    }
}
