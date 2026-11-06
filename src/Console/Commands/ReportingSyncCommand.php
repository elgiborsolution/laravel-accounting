<?php

namespace ESolution\LaravelAccounting\Console\Commands;

class ReportingSyncCommand extends ReportingCommand
{
    protected $signature = 'accounting:reporting-sync
        {--tenant=* : Limit to these tenants (default: every registered source)}
        {--max-batches= : Stop each tenant after this many outbox batches}
        {--ignore-backoff : Retry failed tenants before their next scheduled attempt}';

    protected $description = 'Apply tenant reporting outbox changes to the central reporting tables';

    public function handle(): int
    {
        $max = $this->option('max-batches');

        return $this->renderResults($this->service()->sync(
            $this->tenants(),
            $max === null || $max === '' ? null : max(1, (int) $max),
            (bool) $this->option('ignore-backoff')
        ), ['applied', 'drained', 'synced_through', 'next_attempt_at']);
    }
}
