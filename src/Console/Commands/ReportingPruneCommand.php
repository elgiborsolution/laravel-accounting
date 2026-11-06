<?php

namespace ESolution\LaravelAccounting\Console\Commands;

class ReportingPruneCommand extends ReportingCommand
{
    protected $signature = 'accounting:reporting-prune
        {--tenant=* : Limit to these tenants (default: every registered source)}
        {--days= : Keep acknowledged rows newer than this many days (default: accounting.reporting.prune_after_days)}';

    protected $description = 'Delete acknowledged reporting outbox rows; unacknowledged rows are kept';

    public function handle(): int
    {
        $days = $this->option('days');

        return $this->renderResults($this->service()->prune($days === null || $days === '' ? null : (int) $days, $this->tenants()), ['pruned']);
    }
}
