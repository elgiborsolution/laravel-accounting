<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Models\FiscalPeriod;
use ESolution\LaravelAccounting\Models\JournalEntry;
use ESolution\LaravelAccounting\Models\JournalEntryDetail;
use ESolution\LaravelAccounting\Models\MonthlyBalance;
use ESolution\LaravelAccounting\Repositories\CentralReportingRepository;
use ESolution\LaravelAccounting\Support\AccountingConnectionResolver;
use ESolution\LaravelAccounting\Support\AccountingErrorReporter;
use ESolution\LaravelAccounting\Support\AccountingTableResolver;
use ESolution\LaravelAccounting\Support\ReportingChangeLog;
use ESolution\LaravelAccounting\Support\ReportingDigest;
use ESolution\LaravelAccounting\Support\TenantReportRunner;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Copies tenant-local journals, closing snapshots, fiscal periods and local cutoffs into
 * central tables on accounting.master_data.connection. Supported only with shared master data
 * (accounting.master_data.use_shared_database): accounts, categories and services already live
 * on that connection, are never copied, and copied rows reference their central ids. Every
 * public operation validates the configuration first and throws when it is unsupported.
 *
 * Protocol per tenant (under an exclusive, fenced central lease):
 * - outbox rows only identify changed items; their current state is re-read from the tenant
 *   in one consistent read transaction (state transfer, so retries and reordering are safe);
 * - the central replacement commits atomically, and only then are exactly those outbox rows
 *   acknowledged in the tenant database; a failed acknowledgement only causes a re-apply;
 * - synced_through is the start of the last run that drained every outbox row, i.e. every
 *   change committed before it is in the central copy.
 */
class ReportingSyncService
{
    public function __construct(
        protected CentralReportingRepository $central,
        protected ReportingChangeLog $log,
        protected AccountingTableResolver $tables,
        protected AccountingConnectionResolver $connections
    ) {}

    /** Initial (or forced) full copy, then drain and reconcile. Registers the tenants as sources. */
    public function backfill(array $tenantIds, bool $force = false): array
    {
        $this->central->assertUsable();

        return array_map(
            fn (string $tenantId) => $this->runTenant($tenantId, 'backfill', fn (string $token) => $this->backfillTenant($tenantId, $token, $force)),
            $this->resolve($tenantIds)
        );
    }

    /** Incremental sync of registered (or the given) tenants. */
    public function sync(?array $tenantIds = null, ?int $maxBatches = null, bool $ignoreBackoff = false): array
    {
        $this->central->assertUsable();
        $results = [];
        foreach ($tenantIds === null ? $this->registered() : $this->resolve($tenantIds) as $tenantId) {
            $source = $this->central->source($tenantId);
            if (! $source || $source->backfill_status !== 'complete') {
                $results[] = ['tenant_id' => $tenantId, 'result' => 'not_backfilled'];

                continue;
            }
            if (! $ignoreBackoff && $source->next_attempt_at !== null && $source->next_attempt_at > now()->toDateTimeString()) {
                $results[] = ['tenant_id' => $tenantId, 'result' => 'deferred', 'next_attempt_at' => $source->next_attempt_at];

                continue;
            }
            $results[] = $this->runTenant($tenantId, 'sync', fn (string $token) => $this->syncTenant($tenantId, $token, $maxBatches));
        }

        return $results;
    }

    /** Compare tenant and central digests; repair mismatched months unless $repair is false. */
    public function reconcile(?array $tenantIds = null, bool $repair = true): array
    {
        $this->central->assertUsable();
        $results = [];
        foreach ($tenantIds === null ? $this->registered() : $this->resolve($tenantIds) as $tenantId) {
            $source = $this->central->source($tenantId);
            if (! $source || $source->backfill_status !== 'complete') {
                $results[] = ['tenant_id' => $tenantId, 'result' => 'not_backfilled'];

                continue;
            }
            $results[] = $this->runTenant($tenantId, 'reconcile', fn (string $token) => $this->reconcileTenant($tenantId, $token, $repair));
        }

        return $results;
    }

    /** Delete acknowledged outbox rows older than $days. Unacknowledged rows are never deleted. */
    public function prune(?int $days = null, ?array $tenantIds = null): array
    {
        $this->central->assertUsable();
        $days ??= (int) config('accounting.reporting.prune_after_days', 7);
        $before = now()->subDays(max(0, $days))->toDateTimeString();

        return array_map(fn (string $tenantId) => $this->runTenant($tenantId, 'prune', fn () => [
            'pruned' => DB::connection($this->log->connectionName())->table($this->log->table())
                ->whereNotNull('acknowledged_at')->where('acknowledged_at', '<', $before)->delete(),
        ], false), $tenantIds === null ? $this->registered() : $this->resolve($tenantIds));
    }

    /** Sync state and freshness per source, with the same readiness rules central reports enforce. */
    public function status(?array $tenantIds = null): array
    {
        $this->central->assertUsable();
        $ids = $tenantIds === null ? null : $this->resolve($tenantIds);
        $sources = $this->central->sources($ids)->keyBy('tenant_id');
        $now = now();

        return array_map(function (string $tenantId) use ($sources, $now) {
            $source = $sources->get($tenantId);
            $readiness = $this->central->readiness($source, $now);

            return [
                'tenant_id' => $tenantId,
                'ready' => $readiness['ready'],
                'reasons' => $readiness['reasons'],
                'lag_seconds' => $readiness['lag_seconds'],
                'synced_through' => $source->synced_through ?? null,
                'backfill_status' => $source->backfill_status ?? null,
                'sync_status' => $source->sync_status ?? null,
                'reconcile_status' => $source->reconcile_status ?? null,
                'reconciled_at' => $source->reconciled_at ?? null,
                'last_success_at' => $source->last_success_at ?? null,
                'failures' => (int) ($source->failures ?? 0),
                'next_attempt_at' => $source->next_attempt_at ?? null,
                'last_error' => AccountingErrorReporter::sanitize($source->last_error ?? null),
                'cutoff_date' => $source->cutoff_date ?? null,
                'locked' => $source !== null && $source->lock_owner !== null && $source->lock_expires_at >= $now->toDateTimeString(),
            ];
        }, $ids ?? $sources->keys()->map(fn ($id) => (string) $id)->all());
    }

    // ---------------------------------------------------------------- per-tenant operations

    protected function runTenant(string $tenantId, string $operation, callable $work, bool $recordFailure = true): array
    {
        $token = (string) Str::uuid();

        try {
            if (! $this->central->acquireLock($tenantId, $token)) {
                return ['tenant_id' => $tenantId, 'result' => 'locked'];
            }
        } catch (Throwable $e) {
            $reference = AccountingErrorReporter::report($e, 'Central reporting '.$operation.' failed', ['tenant_id' => $tenantId]);

            return ['tenant_id' => $tenantId, 'result' => 'failed', 'error' => $this->readableFailure($e, $reference)];
        }

        try {
            $output = app(TenantReportRunner::class)->run([$tenantId], function () use ($work, $token) {
                $this->assertSeparateConnections();

                return $work($token);
            })[0]['report'];

            return ['tenant_id' => $tenantId, 'result' => 'ok'] + $output;
        } catch (Throwable $e) {
            // Full details (SQL, trace) go to the log under a reference; stored and returned text stays readable.
            $reference = AccountingErrorReporter::report($e, 'Central reporting '.$operation.' failed', ['tenant_id' => $tenantId]);
            if ($recordFailure) {
                try {
                    $this->central->recordFailure($tenantId, $operation, $e, $reference);
                } catch (Throwable $ignored) {
                    report($ignored);
                }
            }

            return ['tenant_id' => $tenantId, 'result' => 'failed', 'error' => $this->readableFailure($e, $reference)];
        } finally {
            try {
                $this->central->releaseLock($tenantId, $token);
            } catch (Throwable $ignored) {
                // An unreleased lease expires after accounting.reporting.lock_seconds.
            }
        }
    }

    protected function readableFailure(Throwable $e, string $reference): string
    {
        return AccountingErrorReporter::safeMessage($e).' ('.trans('accounting::reporting.reference', ['reference' => $reference]).')';
    }

    protected function syncTenant(string $tenantId, string $token, ?int $maxBatches): array
    {
        $this->requireChangeLog();
        $started = now();
        [$applied, $drained] = $this->drain($tenantId, $token, $maxBatches);
        $through = $drained ? $started : null;
        $this->commitSource($tenantId, $token, $this->succeeded($through));

        return ['applied' => $applied, 'drained' => $drained, 'synced_through' => $through?->toDateTimeString()];
    }

    protected function backfillTenant(string $tenantId, string $token, bool $force): array
    {
        $this->requireChangeLog();
        $source = $this->central->source($tenantId);
        if (! $force && $source?->backfill_status === 'complete') {
            return ['skipped' => 'already backfilled; use --force to copy again'];
        }

        // Outbox rows written from here on are drained after the copy, so concurrent
        // changes are never lost; the copy itself starts from an empty tenant scope.
        $started = now();
        $this->central->db()->transaction(function () use ($tenantId, $token, $started) {
            $this->central->assertLock($tenantId, $token);
            $this->central->clearTenant($tenantId);
            $this->central->updateSource($tenantId, [
                'backfill_status' => 'running', 'backfill_started_at' => $started->toDateTimeString(),
                'backfill_completed_at' => null, 'synced_through' => null, 'reconcile_status' => 'unknown',
                'last_attempt_at' => $started->toDateTimeString(),
            ]);
        });

        $journals = 0;
        $last = null;
        do {
            $ids = JournalEntry::query()->toBase()
                ->when($last !== null, fn ($query) => $query->where('id', '>', $last))
                ->orderBy('id')->limit(500)->pluck('id')->map(fn ($id) => (string) $id)->all();
            if ($ids !== []) {
                $this->applyState($tenantId, $token, $ids, [], false);
                $journals += count($ids);
                $last = end($ids);
            }
        } while (count($ids) === 500);

        $months = $this->sourceMonthKeys();
        foreach (array_chunk($months, 12) as $chunk) {
            $this->applyState($tenantId, $token, [], $chunk, false);
        }
        $this->applyState($tenantId, $token, [], [], true);
        $this->afterBackfillCopy($tenantId);

        [$applied] = $this->drain($tenantId, $token, null);
        $reconcile = $this->reconcileTenant($tenantId, $token, true, false);
        $this->commitSource($tenantId, $token, $this->succeeded($started) + [
            'backfill_status' => 'complete', 'backfill_completed_at' => now()->toDateTimeString(),
        ]);

        return ['journals' => $journals, 'months' => count($months), 'applied' => $applied, 'reconcile' => $reconcile['status']];
    }

    protected function reconcileTenant(string $tenantId, string $token, bool $repair, bool $drainFirst = true): array
    {
        if ($drainFirst) {
            $this->requireChangeLog();
            $this->drain($tenantId, $token, null);
        }

        $diff =$this->differences($tenantId);
        $found = $diff;
        $repaired = [];
        // Concurrent logged writes can land between verification reads; retry a few times
        // before declaring drift. Repair is idempotent state transfer.
        for ($attempt = 0; $diff !== [] && $repair && $attempt < 3; $attempt++) {
            $this->repair($tenantId, $token, $diff);
            $repaired = array_merge_recursive($repaired, $diff);
            $this->drain($tenantId, $token, null);
            $diff = $this->differences($tenantId);
        }
        $status = $diff === [] ? 'ok' : 'drifted';
        $this->commitSource($tenantId, $token, [
            'reconcile_status' => $status,
            'reconciled_at' => now()->toDateTimeString(),
            'reconcile_details' => $this->central->encode(['found' => $found, 'repaired' => $repaired, 'remaining' => $diff]),
        ]);

        return ['status' => $status, 'found' => $found, 'remaining' => $diff];
    }

    /** Test/host seam: runs after the bulk copy and before the post-copy drain. */
    protected function afterBackfillCopy(string $tenantId): void {}

    // ---------------------------------------------------------------- outbox drain and state transfer

    /** @return array{0: int, 1: bool} applied outbox rows, drained to empty */
    protected function drain(string $tenantId, string $token, ?int $maxBatches): array
    {
        $size = max(1, (int) config('accounting.reporting.batch_size', 500));
        $db = DB::connection($this->log->connectionName());
        $applied = 0;
        $batches = 0;
        while (true) {
            $rows = $db->table($this->log->table())->whereNull('acknowledged_at')
                ->orderBy('id')->limit($size)->get(['id', 'aggregate_type', 'aggregate_key']);
            if ($rows->isEmpty()) {
                return [$applied, true];
            }

            $this->applyChanges($tenantId, $token, $rows);
            // Only after the central commit, and only these rows: later or lower-id rows that
            // committed meanwhile stay unacknowledged and are applied by a later batch.
            $this->acknowledge($rows->pluck('id')->all());
            $applied += $rows->count();

            if ($rows->count() < $size) {
                return [$applied, true];
            }
            if ($maxBatches !== null && ++$batches >= $maxBatches) {
                return [$applied, false];
            }
        }
    }

    protected function applyChanges(string $tenantId, string $token, Collection $rows): void
    {
        $keys = fn (string $type) => $rows->where('aggregate_type', $type)->pluck('aggregate_key')
            ->map(fn ($key) => (string) $key)->unique()->values()->all();
        $months = array_values(array_filter($keys(ReportingChangeLog::MONTH), fn (string $key) => preg_match('/^\d{4}-\d{2}$/', $key) === 1));

        $this->applyState($tenantId, $token, $keys(ReportingChangeLog::JOURNAL), $months,
            $rows->contains('aggregate_type', ReportingChangeLog::CUTOFF));
    }

    /** Re-read the items' current tenant state consistently, then replace them in one central transaction. */
    protected function applyState(string $tenantId, string $token, array $journalIds, array $monthKeys, bool $cutoff): void
    {
        if ($journalIds === [] && $monthKeys === [] && ! $cutoff) {
            return;
        }
        $state = $this->consistentRead(fn () => [
            'journals' => $this->readJournals($journalIds),
            'months' => array_combine($monthKeys, array_map(fn (string $key) => $this->readMonth($key), $monthKeys)) ?: [],
            'cutoff' => $cutoff ? $this->readCutoff() : null,
        ]);

        $this->central->db()->transaction(function () use ($tenantId, $token, $journalIds, $state) {
            $this->central->assertLock($tenantId, $token);
            if ($journalIds !== []) {
                $this->central->replaceJournals($tenantId, $journalIds, $state['journals']['headers'], $state['journals']['details']);
            }
            foreach ($state['months'] as $key => $month) {
                [$year, $number] = array_map('intval', explode('-', $key));
                $this->central->replaceMonth($tenantId, $year, $number, $month['periods'], $month['balances']);
            }
            if ($state['cutoff'] !== null) {
                $this->central->updateSource($tenantId, $state['cutoff']);
            }
        });
    }

    protected function acknowledge(array $ids): void
    {
        $db = DB::connection($this->log->connectionName());
        foreach (array_chunk($ids, 500) as $chunk) {
            $db->table($this->log->table())->whereIn('id', $chunk)->update(['acknowledged_at' => now()->toDateTimeString()]);
        }
    }

    protected function readJournals(array $ids): array
    {
        $headers = [];
        $details = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (JournalEntry::query()->toBase()->whereIn('id', $chunk)->get() as $row) {
                $headers[(string) $row->id] = (array) $row;
            }
            foreach (JournalEntryDetail::query()->toBase()->whereIn('journal_entry_id', $chunk)->orderBy('id')->get() as $row) {
                $details[(string) $row->journal_entry_id][] = (array) $row;
            }
        }

        return ['headers' => $headers, 'details' => $details];
    }

    protected function readMonth(string $key): array
    {
        [$year, $month] = array_map('intval', explode('-', $key));

        return [
            'periods' => FiscalPeriod::query()->toBase()->where('year', $year)->where('month', $month)->get()
                ->map(fn ($row) => (array) $row)->all(),
            'balances' => MonthlyBalance::query()->toBase()->where('fiscal_year', $year)->where('fiscal_month', $month)
                ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        ];
    }

    /** Local cutoff state (metadata only; shared cutoffs already live centrally). */
    protected function readCutoff(): array
    {
        $connection = $this->log->connectionName();
        $table = $this->tables->table('closing_cutoffs');
        if (! Schema::connection($connection)->hasTable($table)) {
            return ['cutoff_available' => false, 'cutoff_date' => null, 'cutoff_updated_at' => null];
        }
        $state = DB::connection($connection)->table($table)->where('id', 1)->first();

        return [
            'cutoff_available' => $state !== null,
            'cutoff_date' => $state?->cutoff_date,
            'cutoff_updated_at' => $state?->updated_at,
        ];
    }

    /** One tenant read transaction; REPEATABLE READ on MySQL/MariaDB/PostgreSQL. */
    protected function consistentRead(callable $callback): mixed
    {
        $db = DB::connection($this->log->connectionName());
        $driver = $db->getDriverName();
        if ($db->transactionLevel() === 0 && in_array($driver, ['mysql', 'mariadb'], true)) {
            $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return $db->transaction(function () use ($db, $driver, $callback) {
            if ($driver === 'pgsql' && $db->transactionLevel() === 1) {
                $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            return $callback();
        });
    }

    // ---------------------------------------------------------------- reconciliation

    protected function differences(string $tenantId): array
    {
        $source = $this->consistentRead(fn () => $this->sourceDigests());
        $central = $this->centralDigests($tenantId);
        $diff = [];
        foreach (['journal_months' => 'journals', 'snapshot_months' => 'months'] as $label => $key) {
            $months = array_unique(array_merge(array_keys($source[$key]), array_keys($central[$key])));
            sort($months);
            $mismatched = array_values(array_filter($months, fn ($month) => ($source[$key][$month] ?? null) !== ($central[$key][$month] ?? null)));
            if ($mismatched !== []) {
                $diff[$label] = $mismatched;
            }
        }
        if ($source['cutoff'] !== $central['cutoff']) {
            $diff['cutoff'] = true;
        }

        return $diff;
    }

    protected function repair(string $tenantId, string $token, array $diff): void
    {
        $months = $diff['journal_months'] ?? [];
        if ($months !== []) {
            $ids = $this->consistentRead(function () use ($months) {
                $ids = [];
                JournalEntry::query()->toBase()->select(['id', 'trx_date'])->orderBy('id')
                    ->chunk(2000, function ($rows) use (&$ids, $months) {
                        foreach ($rows as $row) {
                            if (in_array($this->monthKey($row->trx_date), $months, true)) {
                                $ids[] = (string) $row->id;
                            }
                        }
                    });

                return $ids;
            });
            $central = $this->central->journalIdsByMonths($tenantId, array_map(fn ($month) => $month === 'none' ? null : $month, $months));
            foreach (array_chunk(array_values(array_unique(array_merge($ids, $central))), 500) as $chunk) {
                $this->applyState($tenantId, $token, $chunk, [], false);
            }
        }
        foreach (array_chunk($diff['snapshot_months'] ?? [], 12) as $chunk) {
            $this->applyState($tenantId, $token, [], array_values(array_filter($chunk, fn ($month) => $month !== 'none')), false);
        }
        if (! empty($diff['cutoff'])) {
            $this->applyState($tenantId, $token, [], [], true);
        }
    }

    /**
     * Order-independent per-month digests (XOR of per-row SHA-256 plus row counts) over the
     * exact source rows, so reconciliation streams without sorting across drivers.
     */
    protected function sourceDigests(): array
    {
        $digests = new ReportingDigest;
        JournalEntry::query()->toBase()->orderBy('id')->chunk(1000, function ($rows) use ($digests) {
            foreach ($rows as $row) {
                $row = (array) $row;
                $digests->add('journals', $this->monthKey($row['trx_date'] ?? null), 'h', $this->central->encode($row));
            }
        });
        $details = (new JournalEntryDetail)->getTable();
        $journals = (new JournalEntry)->getTable();
        JournalEntryDetail::query()->toBase()
            ->join($journals, $journals.'.id', '=', $details.'.journal_entry_id')
            ->select([$details.'.*', $journals.'.trx_date as reporting_journal_trx_date'])
            ->orderBy($details.'.id')
            ->chunk(1000, function ($rows) use ($digests) {
                foreach ($rows as $row) {
                    $row = (array) $row;
                    $month = $this->monthKey($row['reporting_journal_trx_date']);
                    unset($row['reporting_journal_trx_date']);
                    $digests->add('journals', $month, 'd', $this->central->encode($row));
                }
            });
        foreach (FiscalPeriod::query()->toBase()->get() as $row) {
            $row = (array) $row;
            $digests->add('months', sprintf('%04d-%02d', $row['year'], $row['month']), 'p', $this->central->encode($row));
        }
        MonthlyBalance::query()->toBase()->orderBy('id')->chunk(2000, function ($rows) use ($digests) {
            foreach ($rows as $row) {
                $row = (array) $row;
                $digests->add('months', sprintf('%04d-%02d', $row['fiscal_year'], $row['fiscal_month']), 'b', $this->central->encode($row));
            }
        });
        $cutoff = $this->readCutoff();

        return [
            'journals' => $digests->finish('journals'),
            'months' => $digests->finish('months'),
            'cutoff' => $this->cutoffDigest($cutoff),
        ];
    }

    protected function centralDigests(string $tenantId): array
    {
        $digests = new ReportingDigest;
        $this->central->journalRows($tenantId, fn (array $row) => $digests->add('journals', $this->monthKey($row['trx_date'] ?? null), 'h', $this->central->encode($row)));
        $this->central->detailRows($tenantId, fn (array $row, array $journal) => $digests->add('journals', $this->monthKey($journal['trx_date'] ?? null), 'd', $this->central->encode($row)));
        foreach ($this->central->periodRows($tenantId) as $row) {
            $digests->add('months', sprintf('%04d-%02d', $row['year'], $row['month']), 'p', $this->central->encode($row));
        }
        $this->central->balanceRows($tenantId, fn (array $row) => $digests->add('months', sprintf('%04d-%02d', $row['fiscal_year'], $row['fiscal_month']), 'b', $this->central->encode($row)));
        $source = $this->central->source($tenantId);

        return [
            'journals' => $digests->finish('journals'),
            'months' => $digests->finish('months'),
            'cutoff' => $this->cutoffDigest([
                'cutoff_available' => (bool) ($source->cutoff_available ?? false),
                'cutoff_date' => $source->cutoff_date ?? null,
                'cutoff_updated_at' => $source->cutoff_updated_at ?? null,
            ]),
        ];
    }

    protected function cutoffDigest(array $cutoff): string
    {
        $date = $cutoff['cutoff_date'] === null ? null : Carbon::parse($cutoff['cutoff_date'])->toDateString();
        $updated = $cutoff['cutoff_updated_at'] === null ? null : Carbon::parse($cutoff['cutoff_updated_at'])->toDateTimeString();

        return json_encode([(bool) $cutoff['cutoff_available'], $date, $updated]);
    }

    // ---------------------------------------------------------------- helpers

    protected function monthKey(mixed $date): string
    {
        return CentralReportingRepository::monthOf($date) ?? 'none';
    }

    protected function sourceMonthKeys(): array
    {
        $keys = FiscalPeriod::query()->toBase()->get(['year', 'month'])
            ->map(fn ($row) => sprintf('%04d-%02d', $row->year, $row->month))
            ->merge(MonthlyBalance::query()->toBase()->distinct()->get(['fiscal_year', 'fiscal_month'])
                ->map(fn ($row) => sprintf('%04d-%02d', $row->fiscal_year, $row->fiscal_month)))
            ->unique()->sort()->values()->all();

        return $keys;
    }

    protected function commitSource(string $tenantId, string $token, array $values): void
    {
        $this->central->db()->transaction(function () use ($tenantId, $token, $values) {
            $this->central->assertLock($tenantId, $token);
            $this->central->updateSource($tenantId, $values);
        });
    }

    protected function succeeded(?Carbon $syncedThrough): array
    {
        $now = now()->toDateTimeString();

        return array_filter([
            'sync_status' => 'ok', 'failures' => 0, 'next_attempt_at' => null, 'last_error' => null,
            'last_attempt_at' => $now, 'last_success_at' => $now,
            'synced_through' => $syncedThrough?->toDateTimeString(),
        ], fn ($value, $key) => $key !== 'synced_through' || $value !== null, ARRAY_FILTER_USE_BOTH);
    }

    protected function requireChangeLog(): void
    {
        if (! $this->log->enabled()) {
            throw new RuntimeException('Enable accounting.reporting.change_log_enabled before backfilling or syncing; unlogged changes cannot be tracked.');
        }
        if (! Schema::connection($this->log->connectionName())->hasTable($this->log->table())) {
            throw new RuntimeException('The reporting change log table is missing. Run the accounting migrations on this tenant database.');
        }
    }

    protected function assertSeparateConnections(): void
    {
        if ($this->log->connectionName() === $this->central->connectionName()) {
            throw new RuntimeException('Central reporting requires the master connection to be separate from tenant transaction connections.');
        }
    }

    protected function resolve(array $tenantIds): array
    {
        return array_map('strval', array_keys(app(TenantReportRunner::class)->resolveTenants($tenantIds)));
    }

    protected function registered(): array
    {
        return $this->central->sources()->pluck('tenant_id')->map(fn ($id) => (string) $id)->all();
    }
}
