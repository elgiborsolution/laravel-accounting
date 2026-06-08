<?php

namespace ESolution\LaravelAccounting\Services;

class AccountingService
{
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
}
