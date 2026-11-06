<?php

namespace ESolution\LaravelAccounting\Console\Commands;

class ReportingReconcileCommand extends ReportingCommand
{
    protected $signature = 'accounting:reporting-reconcile
        {--tenant=* : Limit to these tenants (default: every registered source)}
        {--detect-only : Report and flag drift without repairing it}';

    protected $description = 'Compare tenant and central reporting data by month and repair differences';

    public function handle(): int
    {
        $results = $this->service()->reconcile($this->tenants(), ! $this->option('detect-only'));
        $code = $this->renderResults($results, ['status', 'found', 'remaining']);

        return collect($results)->contains('status', 'drifted') ? self::FAILURE : $code;
    }
}
