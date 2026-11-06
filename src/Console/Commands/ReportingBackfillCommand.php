<?php

namespace ESolution\LaravelAccounting\Console\Commands;

class ReportingBackfillCommand extends ReportingCommand
{
    protected $signature = 'accounting:reporting-backfill
        {--tenant=* : Tenant id or route key (required, repeatable)}
        {--force : Copy again even when the tenant is already backfilled}';

    protected $description = 'Register tenants for central reporting and copy their accounting data (then drain and reconcile)';

    public function handle(): int
    {
        $tenants = $this->tenants();
        if ($tenants === null) {
            $this->error('Pass at least one --tenant.');

            return self::INVALID;
        }

        return $this->renderResults($this->service()->backfill($tenants, (bool) $this->option('force')),
            ['journals', 'months', 'applied', 'reconcile', 'skipped']);
    }
}
