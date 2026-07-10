<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Repositories\ServiceAccountRepository;

class MappingService
{
    public function __construct(protected ServiceAccountRepository $repository) {}

    public function findByKey(string $key)
    {
        return $this->repository->findByKey($key);
    }

    public function getByService($serviceId)
    {
        return $this->repository->forServiceId($serviceId);
    }
}
