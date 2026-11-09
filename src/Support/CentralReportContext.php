<?php

namespace ESolution\LaravelAccounting\Support;

use ESolution\LaravelAccounting\Repositories\CentralReportingRepository;
use Illuminate\Support\Collection;

/**
 * Active only while a central-mode tenant report runs (read_source = central). Report
 * services ask it for tenant transaction data (journals, fiscal periods, snapshots) first and
 * fall back to the tenant connection when inactive, so calculations, payload shapes and
 * stable-code checks are shared by both modes. Accounts, categories and services are not
 * provided here: central reporting requires shared master data, so the report services read
 * them from their existing master tables in both modes. It never initializes tenancy or
 * touches a tenant connection.
 */
class CentralReportContext
{
    protected ?array $current = null;

    public function __construct(protected CentralReportingRepository $central) {}

    /**
     * @param  array<string, mixed>  $tenants  canonical tenant id => resolved tenant record
     */
    public function run(array $tenants, callable $report): array
    {
        // Reject unsupported configurations before any central read.
        $this->central->assertUsable();

        // Readiness and every read share one central snapshot.
        return $this->central->readConsistently(function () use ($tenants, $report) {
            $this->central->assertReady(array_map('strval', array_keys($tenants)));
            $previous = $this->current;
            $results = [];

            try {
                foreach (array_keys($tenants) as $tenantId) {
                    $tenantId = (string) $tenantId;
                    $this->current = ['tenant_id' => $tenantId];
                    $results[] = ['tenant_id' => $tenantId, 'report' => $report()];
                }

                return $results;
            } finally {
                $this->current = $previous;
            }
        });
    }

    public function active(): bool
    {
        return $this->current !== null;
    }

    public function tenantId(): ?string
    {
        return $this->current['tenant_id'] ?? null;
    }

    public function monthlyBalances(int $year, int $month): ?Collection
    {
        return $this->active() ? $this->central->monthlyBalances($this->current['tenant_id'], $year, $month) : null;
    }

    public function fiscalPeriods(): ?Collection
    {
        return $this->active() ? $this->central->fiscalPeriods($this->current['tenant_id']) : null;
    }

    public function snapshotCounts(): ?Collection
    {
        return $this->active() ? $this->central->snapshotCounts($this->current['tenant_id']) : null;
    }

    public function earliestPostedDate(): mixed
    {
        return $this->central->earliestPostedDate($this->current['tenant_id']);
    }

    public function legacySignedSnapshotMonths(array $debitNormalAccountIds): Collection
    {
        return $this->central->legacySignedSnapshotMonths($this->current['tenant_id'], $debitNormalAccountIds);
    }

    /** @return array{0: bool, 1: mixed} [active, earliest journal date of any status] */
    public function earliestJournalDate(): array
    {
        return $this->active() ? [true, $this->central->earliestJournalDate($this->current['tenant_id'])] : [false, null];
    }

    public function balanceQuery(): ?\Illuminate\Database\Query\Builder
    {
        return $this->active() ? $this->central->balanceQuery($this->current['tenant_id']) : null;
    }

    public function movementQuery(): ?array
    {
        return $this->active() ? $this->central->movementQuery($this->current['tenant_id']) : null;
    }
}
