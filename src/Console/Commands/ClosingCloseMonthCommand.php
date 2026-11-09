<?php

namespace ESolution\LaravelAccounting\Console\Commands;

use ESolution\LaravelAccounting\Services\ClosingService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Thin wrapper over the ClosingService global monthly-closing methods; the host registers the schedule. */
class ClosingCloseMonthCommand extends Command
{
    protected $signature = 'accounting:closing-close-month
        {--year= : Year to close (with --month; defaults to the latest month that is due)}
        {--month= : Month to close (1-12)}
        {--tenant=* : Tenant id or route key; omit for every tenant}
        {--actor= : Actor id recorded on the run and the closed periods}
        {--scheduled : Scheduler mode: one run per period, later calls resume it, reopened periods are skipped}
        {--recover : Requeue stale or never-run tenants of unfinished runs first}
        {--retry= : Retry the failed tenants of this run id}
        {--status= : Show this run id}';

    protected $description = 'Close one accounting month in every (or the selected) tenant and report per-tenant results';

    public function handle(ClosingService $closing): int
    {
        try {
            if ($this->option('status') !== null) {
                return $this->render($closing->getGlobalCloseRun((int) $this->option('status')));
            }
            if ($this->option('retry') !== null) {
                return $this->render($closing->retryGlobalCloseRun((int) $this->option('retry'), $this->tenants()));
            }
            if ($this->option('recover')) {
                foreach ($closing->recoverGlobalCloseRuns() as $recovered) {
                    $this->line('Run #'.$recovered['run_id'].': requeued '.$recovered['dispatched'].' tenant(s).');
                }
                if (! $this->option('scheduled') && $this->option('year') === null && $this->option('month') === null) {
                    return self::SUCCESS;
                }
            }
            if (($this->option('year') === null) !== ($this->option('month') === null)) {
                $this->error('Pass --year and --month together, or neither for the latest due month.');

                return self::FAILURE;
            }
            $period = $this->option('year') === null
                ? $closing->defaultGlobalCloseMonth()
                : ['year' => (int) $this->option('year'), 'month' => (int) $this->option('month')];

            return $this->render($closing->closeMonthGlobally(
                $period['year'], $period['month'], $this->tenants(), $this->option('actor'), (bool) $this->option('scheduled')
            ));
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
        }

        return self::FAILURE;
    }

    protected function tenants(): ?array
    {
        $tenants = array_values(array_filter((array) $this->option('tenant'), fn ($id) => $id !== null && $id !== ''));

        return $tenants === [] ? null : $tenants;
    }

    protected function render(array $run): int
    {
        $this->info('Closing run #'.$run['id'].' ('.$run['trigger'].') for '.$run['period'].': '.$run['status']);
        $this->table(['tenant_id', 'status', 'attempts', 'debit', 'credit', 'message'], array_map(function (array $tenant) {
            $detail = $tenant['error'] ?? $tenant['reason'];

            return [
                $tenant['tenant_id'],
                $tenant['status'].($tenant['stale'] ? ' (stale)' : ''),
                $tenant['attempts'],
                $tenant['total_debit'] === null ? '' : number_format($tenant['total_debit'], 2),
                $tenant['total_credit'] === null ? '' : number_format($tenant['total_credit'], 2),
                $detail === null ? '' : $detail['message'].' '.$detail['action'],
            ];
        }, $run['tenants']));

        return $run['counts']['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
