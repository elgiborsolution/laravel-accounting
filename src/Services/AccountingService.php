<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Enums\AccountingServiceCode;
use ESolution\LaravelAccounting\Models\Service;
use ESolution\LaravelAccounting\Repositories\ServiceRepository;
use ESolution\LaravelAccounting\Support\ServiceCatalog;

class AccountingService
{
    public function __construct(
        protected ServiceCatalog $serviceCatalog,
        protected ServiceRepository $services
    ) {}

    public function journal()
    {
        return app(JournalService::class);
    }

    public function coa()
    {
        return app(CoaService::class);
    }

    public function mapping()
    {
        return app(MappingService::class);
    }

    public function closing()
    {
        return app(ClosingService::class);
    }

    public function report()
    {
        return app(ReportService::class);
    }

    public function service(string|AccountingServiceCode $service): ?Service
    {
        return $this->services->findByCode($this->serviceCatalog->normalizeCode($service));
    }

    public function catalog(): ServiceCatalog
    {
        return $this->serviceCatalog;
    }
}
