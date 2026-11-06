<?php

namespace ESolution\LaravelAccounting\Console\Commands;

class ReportingStatusCommand extends ReportingCommand
{
    protected $signature = 'accounting:reporting-status
        {--tenant=* : Limit to these tenants (default: every registered source)}';

    protected $description = 'Show central reporting sync state, freshness and readiness per tenant';

    public function handle(): int
    {
        $rows = array_map(fn (array $row) => ['result' => $row['ready'] ? 'ready' : 'not ready'] + $row,
            $this->service()->status($this->tenants()));
        $this->renderResults($rows, ['synced_through', 'lag_seconds', 'backfill_status', 'sync_status', 'reconcile_status', 'reasons']);

        return collect($rows)->every(fn (array $row) => $row['ready']) ? self::SUCCESS : self::FAILURE;
    }
}
