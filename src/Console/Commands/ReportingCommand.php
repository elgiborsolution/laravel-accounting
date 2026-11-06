<?php

namespace ESolution\LaravelAccounting\Console\Commands;

use ESolution\LaravelAccounting\Repositories\CentralReportingRepository;
use ESolution\LaravelAccounting\Services\ReportingSyncService;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Shared output for the accounting:reporting-* commands. */
abstract class ReportingCommand extends Command
{
    /**
     * Reject unsupported configurations (anything but shared master data on a configured
     * master connection) before any tenant or central work, with a non-zero exit code.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            app(CentralReportingRepository::class)->assertUsable();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return parent::execute($input, $output);
    }

    protected function service(): ReportingSyncService
    {
        return app(ReportingSyncService::class);
    }

    /** --tenant values, or null for every registered source. */
    protected function tenants(): ?array
    {
        $tenants = array_values(array_filter((array) $this->option('tenant'), fn ($id) => $id !== null && $id !== ''));

        return $tenants === [] ? null : $tenants;
    }

    /** Print per-tenant results; failures make the command exit non-zero. */
    protected function renderResults(array $results, array $columns): int
    {
        $this->table(array_merge(['tenant_id', 'result'], $columns), array_map(fn (array $row) => array_map(
            fn ($value) => is_array($value) ? json_encode($value) : (is_bool($value) ? ($value ? 'yes' : 'no') : (string) ($value ?? '')),
            array_merge(['tenant_id' => $row['tenant_id'], 'result' => $row['result'] ?? ''], array_intersect_key($row + array_fill_keys($columns, null), array_flip($columns)))
        ), $results));
        foreach ($results as $row) {
            if (($row['result'] ?? null) === 'failed') {
                $this->error('['.$row['tenant_id'].'] '.($row['error'] ?? 'failed'));
            }
        }

        return collect($results)->contains('result', 'failed') ? self::FAILURE : self::SUCCESS;
    }
}
