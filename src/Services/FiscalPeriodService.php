<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Exceptions\AccountingPeriodLockedException;
use ESolution\LaravelAccounting\Models\FiscalPeriod;
use ESolution\LaravelAccounting\Repositories\FiscalPeriodRepository;
use ESolution\LaravelAccounting\Support\AccountingConnectionResolver;
use ESolution\LaravelAccounting\Support\AccountingTableResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FiscalPeriodService
{
    protected int $sharedLockDepth = 0;

    public function __construct(protected FiscalPeriodRepository $periods) {}

    public function ensureForDate($date): FiscalPeriod
    {
        return $this->periods->firstOrCreateForDate(Carbon::parse($date));
    }

    public function ensureThroughCurrentMonth(?Carbon $fromDate = null): Collection
    {
        $startDate = $fromDate ? $fromDate->copy() : $this->periods->resolveEarliestJournalDate();

        if (! $startDate) {
            $startDate = now()->startOfMonth();
        }

        $startDate = $startDate->startOfMonth();

        return $this->periods->ensureThroughCurrentMonth($startDate);
    }

    public function ensureForJournalDate($date): FiscalPeriod
    {
        return $this->ensureForDate($date);
    }

    /** Call inside the write transaction to retain the cutoff row lock. */
    public function assertDateOpen($date, bool $includeCutoff = true): void
    {
        $date = Carbon::parse($date);
        $cutoff = $includeCutoff ? $this->lockClosingCutoff() : null;
        if ($cutoff && $this->cutoffMode() === 'shared' && $cutoff->tenant_ids !== null) {
            $scope = json_decode($cutoff->tenant_ids, true, 512, JSON_THROW_ON_ERROR);
            if (! in_array($this->currentCutoffTenantId(), $scope, true)) {
                throw new RuntimeException('The active tenant is not in the shared closing scope.');
            }
        }
        if ($cutoff?->cutoff_date !== null && $date->toDateString() <= $cutoff->cutoff_date) {
            throw new AccountingPeriodLockedException('Accounting is closed through '.$cutoff->cutoff_date.' inclusively.');
        }
        if ($this->ensureForJournalDate($date)->is_closed) {
            throw new AccountingPeriodLockedException;
        }
    }

    public function lockClosingCutoff(): ?object
    {
        if (! config('accounting.closing.cutoff_enabled', false)) {
            return null;
        }
        $connection = $this->cutoffConnection();
        $table = app(AccountingTableResolver::class)->table($this->cutoffMode() === 'shared' ? 'shared_closing_cutoffs' : 'closing_cutoffs');
        $state = DB::connection($connection)->table($table)->where('id', 1)->lockForUpdate()->first();
        if (! $state) {
            throw new RuntimeException('Closing cutoff state is missing. Run the accounting migrations on this transaction database.');
        }

        return $state;
    }

    public function cutoffMode(): string
    {
        $mode = config('accounting.closing.cutoff_mode', 'per_tenant');
        if (! in_array($mode, ['per_tenant', 'shared'], true)) {
            throw new RuntimeException('Closing cutoff mode must be per_tenant or shared.');
        }

        return $mode;
    }

    /**
     * Shared mode stores cutoffs on the configured master connection
     * (accounting.master_data.connection). The name is used whether or not
     * master_data.use_shared_database moves master data there as well.
     */
    public function cutoffConnection(): string
    {
        $connections = app(AccountingConnectionResolver::class);
        if ($this->cutoffMode() === 'per_tenant') {
            return $connections->resolveTransactionDataConnection();
        }
        $name = $connections->masterConnectionName();
        if ($name === null || ! config('database.connections.'.$name)) {
            throw new RuntimeException('Shared closing requires accounting.master_data.connection (ACCOUNTING_MASTER_CONNECTION) to name a configured central connection.');
        }

        return $name;
    }

    /** The central transaction outlives the tenant commit; one lock order everywhere. */
    public function withClosingLock(callable $callback): mixed
    {
        if (! config('accounting.closing.cutoff_enabled', false) || $this->cutoffMode() === 'per_tenant') {
            return $callback();
        }

        $central = DB::connection($this->cutoffConnection());
        $tenant = DB::connection(app(AccountingConnectionResolver::class)->resolveTransactionDataConnection());
        if ($tenant->transactionLevel() > 0 && ($this->sharedLockDepth === 0 || $central->transactionLevel() === 0)) {
            throw new RuntimeException('Acquire the shared closing lock before starting a tenant transaction.');
        }

        return $central->transaction(function () use ($callback) {
            $this->lockClosingCutoff();
            $this->sharedLockDepth++;

            try {
                return $callback();
            } finally {
                $this->sharedLockDepth--;
            }
        });
    }

    protected function currentCutoffTenantId(): ?string
    {
        return function_exists('tenancy') && tenancy()->initialized
            ? (string) tenancy()->tenant->getKey() : null;
    }
}
