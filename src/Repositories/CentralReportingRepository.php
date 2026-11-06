<?php

namespace ESolution\LaravelAccounting\Repositories;

use ESolution\LaravelAccounting\Enums\JournalStatus;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use ESolution\LaravelAccounting\Models\FiscalPeriod;
use ESolution\LaravelAccounting\Models\Service;
use ESolution\LaravelAccounting\Support\AccountingConnectionResolver;
use ESolution\LaravelAccounting\Support\AccountingTableResolver;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Central reporting tables on accounting.master_data.connection: tenant journal, fiscal
 * period and snapshot copies keyed by (tenant_id, source id), sync state, leases and the
 * reads used by central-mode reports. Source rows are stored verbatim in `payload`.
 *
 * Supported only with shared master data: accounts, categories and services are read
 * directly from their existing master tables on the same connection, and copied journal
 * and snapshot rows reference those central ids.
 */
class CentralReportingRepository
{
    public const READY = 'ready';

    /** Master models central reports read directly instead of copying. */
    public const MASTER_MODELS = [AccountCategory::class, Account::class, Service::class];

    public function __construct(
        protected AccountingConnectionResolver $connections,
        protected AccountingTableResolver $tables
    ) {}

    public function connectionName(): string
    {
        $name = $this->connections->masterConnectionName();
        if ($name === null || ! config('database.connections.'.$name)) {
            throw new RuntimeException('Central reporting requires accounting.master_data.connection (ACCOUNTING_MASTER_CONNECTION) to name a configured central connection.');
        }

        return $name;
    }

    /**
     * Reject every configuration except shared master data on a configured master connection
     * that holds the account, category and service tables. The central reporting migration
     * skips (no-op) unless this passes; every sync operation, command and central report read
     * calls it through assertUsable() and throws.
     *
     * @return string the master connection name
     */
    public function assertSupported(): string
    {
        if (! $this->connections->useSharedMasterDatabase()) {
            throw new RuntimeException('Central reporting is supported only with shared master data: set accounting.master_data.use_shared_database (ACCOUNTING_USE_SHARED_DATABASE) to true. Tenant-local master data is not supported.');
        }
        $connection = $this->connectionName();
        foreach (self::MASTER_MODELS as $class) {
            $model = new $class;
            $resolved = $model->getConnectionName() ?: DB::getDefaultConnection();
            if ($resolved !== $connection) {
                throw new RuntimeException('Central reporting requires '.class_basename($class).' to use the master connection ['.$connection.'], but it resolves to ['.$resolved.'].');
            }
            if (! Schema::connection($connection)->hasTable($model->getTable())) {
                throw new RuntimeException('Central reporting requires the master table ['.$model->getTable().'] on connection ['.$connection.']. Run the master data migrations first.');
            }
        }

        return $connection;
    }

    /** Whether assertSupported() would accept the configuration (used by the migration to skip quietly). */
    public function supported(): bool
    {
        try {
            $this->assertSupported();

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * assertSupported() plus the central reporting tables. The migration creates them only on a
     * run that targets the master connection with a supported configuration, so an install that
     * became supported after migrating is told how to create them instead of failing in SQL.
     *
     * @return string the master connection name
     */
    public function assertUsable(): string
    {
        $connection = $this->assertSupported();
        if (! Schema::connection($connection)->hasTable($this->table('sources'))) {
            throw new RuntimeException('Central reporting tables are missing on connection ['.$connection.']. Run the accounting migrations against that connection (php artisan migrate --database='.$connection.'); if 2026_11_06_000019_create_acc_reporting_central_tables was already recorded there while unsupported, roll it back and run it again.');
        }

        return $connection;
    }

    public function db(): ConnectionInterface
    {
        return DB::connection($this->connectionName());
    }

    public function table(string $name): string
    {
        return $this->tables->table('reporting_'.$name);
    }

    // ---------------------------------------------------------------- sources, leases, status

    public function ensureSource(string $tenantId): void
    {
        $this->db()->table($this->table('sources'))->insertOrIgnore([
            'tenant_id' => $tenantId, 'updated_at' => $this->now(),
        ]);
    }

    public function source(string $tenantId): ?object
    {
        return $this->db()->table($this->table('sources'))->where('tenant_id', $tenantId)->first();
    }

    public function sources(?array $tenantIds = null): Collection
    {
        return $this->db()->table($this->table('sources'))
            ->when($tenantIds !== null, fn ($query) => $query->whereIn('tenant_id', $tenantIds))
            ->orderBy('tenant_id')->get();
    }

    /** Exclusive per-tenant lease; an expired lease may be taken over. */
    public function acquireLock(string $tenantId, string $token): bool
    {
        $this->ensureSource($tenantId);
        $now = $this->now();

        return $this->db()->table($this->table('sources'))->where('tenant_id', $tenantId)
            ->where(fn ($query) => $query->whereNull('lock_owner')->orWhere('lock_expires_at', '<', $now)->orWhere('lock_owner', $token))
            ->update(['lock_owner' => $token, 'lock_expires_at' => $this->leaseExpiry(), 'updated_at' => $now]) === 1;
    }

    /**
     * Fencing inside every central write transaction: the batch commits only while this
     * worker still owns the lease, and the lease is extended.
     */
    public function assertLock(string $tenantId, string $token): void
    {
        $source = $this->db()->table($this->table('sources'))->where('tenant_id', $tenantId)->lockForUpdate()->first();
        if (! $source || $source->lock_owner !== $token || $source->lock_expires_at < $this->now()) {
            throw new RuntimeException('Central reporting sync lease for tenant ['.$tenantId.'] was lost; the batch was not committed.');
        }
        $this->db()->table($this->table('sources'))->where('tenant_id', $tenantId)
            ->update(['lock_expires_at' => $this->leaseExpiry()]);
    }

    public function releaseLock(string $tenantId, string $token): void
    {
        $this->db()->table($this->table('sources'))->where('tenant_id', $tenantId)->where('lock_owner', $token)
            ->update(['lock_owner' => null, 'lock_expires_at' => null]);
    }

    public function updateSource(string $tenantId, array $values): void
    {
        $this->db()->table($this->table('sources'))->where('tenant_id', $tenantId)
            ->update($values + ['updated_at' => $this->now()]);
    }

    public function recordFailure(string $tenantId, string $operation, Throwable $error): void
    {
        $this->ensureSource($tenantId);
        $source = $this->source($tenantId);
        $failures = (int) ($source->failures ?? 0) + 1;
        $base = max(1, (int) config('accounting.reporting.retry_base_seconds', 60));
        $delay = min($base * 2 ** min($failures - 1, 20), max($base, (int) config('accounting.reporting.retry_max_seconds', 3600)));
        $values = [
            'sync_status' => 'failed',
            'failures' => $failures,
            'last_attempt_at' => $this->now(),
            'next_attempt_at' => now()->addSeconds($delay)->toDateTimeString(),
            'last_error' => mb_substr($operation.': '.get_class($error).': '.$error->getMessage(), 0, 2000),
        ];
        if ($operation === 'backfill') {
            $values['backfill_status'] = 'failed';
        }
        $this->updateSource($tenantId, $values);
    }

    /** @return array{ready: bool, reasons: list<string>, lag_seconds: ?int} */
    public function readiness(?object $source, ?Carbon $now = null): array
    {
        $now ??= now();
        if (! $source) {
            return ['ready' => false, 'reasons' => ['not registered; run accounting:reporting-backfill'], 'lag_seconds' => null];
        }
        $reasons = [];
        if ($source->backfill_status !== 'complete') {
            $reasons[] = 'backfill '.$source->backfill_status;
        }
        if ($source->sync_status === 'failed') {
            $reasons[] = 'sync failed: '.$source->last_error;
        }
        if ($source->reconcile_status === 'drifted') {
            $reasons[] = 'drift detected; run accounting:reporting-reconcile';
        }
        $lag = $source->synced_through === null ? null : max(0, Carbon::parse($source->synced_through)->diffInSeconds($now, false));
        $limit = $this->maxLagSeconds();
        if ($lag === null) {
            $reasons[] = 'never fully synced';
        } elseif ($limit !== null && $lag > $limit) {
            $reasons[] = 'stale: synced through '.$source->synced_through.' ('.(int) $lag.'s > '.$limit.'s)';
        }

        return ['ready' => $reasons === [], 'reasons' => $reasons, 'lag_seconds' => $lag === null ? null : (int) $lag];
    }

    /**
     * Reject the whole request unless every selected source is ready; never partial totals.
     *
     * @return array<string, object> canonical tenant id => source row
     */
    public function assertReady(array $tenantIds): array
    {
        $sources = $this->sources($tenantIds)->keyBy('tenant_id');
        $errors = [];
        foreach ($tenantIds as $tenantId) {
            $readiness = $this->readiness($sources->get($tenantId));
            if (! $readiness['ready']) {
                $errors[] = 'Tenant ['.$tenantId.'] central reporting is not ready: '.implode('; ', $readiness['reasons']).'.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(['reporting_sync' => $errors]);
        }

        return $sources->all();
    }

    public function maxLagSeconds(): ?int
    {
        $limit = config('accounting.reporting.max_lag_seconds', 900);
        if ($limit === null || $limit === '') {
            return null;
        }
        if (filter_var($limit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
            throw new RuntimeException('accounting.reporting.max_lag_seconds must be a non-negative integer or null.');
        }

        return (int) $limit;
    }

    // ---------------------------------------------------------------- writes (call inside a central transaction)

    /** State transfer: headers present at the source are replaced, absent ones are deleted. */
    public function replaceJournals(string $tenantId, array $journalIds, array $headers, array $detailsByJournal): void
    {
        foreach (array_chunk($journalIds, 200) as $chunk) {
            $this->db()->table($this->table('journal_entry_details'))->where('tenant_id', $tenantId)->whereIn('journal_entry_id', $chunk)->delete();
            $this->db()->table($this->table('journal_entries'))->where('tenant_id', $tenantId)->whereIn('id', $chunk)->delete();
        }
        $rows = [];
        $details = [];
        foreach ($journalIds as $id) {
            $header = $headers[$id] ?? null;
            if ($header === null) {
                continue;
            }
            $rows[] = [
                'tenant_id' => $tenantId, 'id' => (string) $header['id'], 'trx_date' => $header['trx_date'] ?? null,
                'status' => $header['status'] ?? null, 'service_id' => $header['service_id'] ?? null,
                'source_type' => $header['source_type'] ?? null, 'payload' => $this->encode($header),
            ];
            foreach ($detailsByJournal[$id] ?? [] as $detail) {
                $details[] = [
                    'tenant_id' => $tenantId, 'id' => (string) $detail['id'], 'journal_entry_id' => (string) $detail['journal_entry_id'],
                    'account_id' => $detail['account_id'] ?? null, 'debit' => $detail['debit'] ?? 0, 'credit' => $detail['credit'] ?? 0,
                    'payload' => $this->encode($detail),
                ];
            }
        }
        $this->insert('journal_entries', $rows);
        $this->insert('journal_entry_details', $details);
    }

    /** Replace one month's fiscal period and its entire snapshot set exactly as the source holds them. */
    public function replaceMonth(string $tenantId, int $year, int $month, array $periods, array $balances): void
    {
        $this->db()->table($this->table('fiscal_periods'))->where('tenant_id', $tenantId)->where('year', $year)->where('month', $month)->delete();
        $this->db()->table($this->table('monthly_balances'))->where('tenant_id', $tenantId)
            ->where('fiscal_year', $year)->where('fiscal_month', $month)->delete();
        $this->insert('fiscal_periods', array_map(fn (array $period) => [
            'tenant_id' => $tenantId, 'id' => (string) $period['id'], 'year' => (int) $period['year'], 'month' => (int) $period['month'],
            'payload' => $this->encode($period),
        ], $periods));
        $this->insert('monthly_balances', array_map(fn (array $row) => [
            'tenant_id' => $tenantId, 'id' => (string) $row['id'], 'fiscal_year' => (int) $row['fiscal_year'],
            'fiscal_month' => (int) $row['fiscal_month'], 'account_id' => (string) $row['account_id'],
            'opening_balance' => $row['opening_balance'] ?? 0, 'total_debit' => $row['total_debit'] ?? 0,
            'total_credit' => $row['total_credit'] ?? 0, 'ending_balance' => $row['ending_balance'] ?? 0,
            'journal_count' => $row['journal_count'] ?? 0, 'payload' => $this->encode($row),
        ], $balances));
    }

    public function clearTenant(string $tenantId): void
    {
        foreach (['journal_entry_details', 'journal_entries', 'fiscal_periods', 'monthly_balances'] as $table) {
            $this->db()->table($this->table($table))->where('tenant_id', $tenantId)->delete();
        }
    }

    // ---------------------------------------------------------------- reads used by reconciliation

    public function journalRows(string $tenantId, callable $each, int $chunk = 1000): void
    {
        $this->db()->table($this->table('journal_entries'))->where('tenant_id', $tenantId)
            ->orderBy('id')->select(['id', 'payload'])
            ->chunk($chunk, function ($rows) use ($each) {
                foreach ($rows as $row) {
                    $each($this->decode($row->payload));
                }
            });
    }

    public function detailRows(string $tenantId, callable $each, int $chunk = 1000): void
    {
        $details = $this->table('journal_entry_details');
        $journals = $this->table('journal_entries');
        $this->db()->table($details)
            ->join($journals, fn ($join) => $join->on($journals.'.id', '=', $details.'.journal_entry_id')
                ->on($journals.'.tenant_id', '=', $details.'.tenant_id'))
            ->where($details.'.tenant_id', $tenantId)
            ->orderBy($details.'.id')
            ->select([$details.'.id', $details.'.payload', $journals.'.payload as journal_payload'])
            ->chunk($chunk, function ($rows) use ($each) {
                foreach ($rows as $row) {
                    $each($this->decode($row->payload), $this->decode($row->journal_payload));
                }
            });
    }

    public function periodRows(string $tenantId): array
    {
        return $this->db()->table($this->table('fiscal_periods'))->where('tenant_id', $tenantId)->orderBy('id')
            ->pluck('payload')->map(fn ($payload) => $this->decode($payload))->all();
    }

    public function balanceRows(string $tenantId, callable $each, int $chunk = 2000): void
    {
        $this->db()->table($this->table('monthly_balances'))->where('tenant_id', $tenantId)
            ->orderBy('id')->select(['id', 'payload'])
            ->chunk($chunk, function ($rows) use ($each) {
                foreach ($rows as $row) {
                    $each($this->decode($row->payload));
                }
            });
    }

    public function journalIdsByMonths(string $tenantId, array $monthKeys): array
    {
        $ids = [];
        $this->journalRows($tenantId, function (array $journal) use (&$ids, $monthKeys) {
            if (in_array(self::monthOf($journal['trx_date'] ?? null), $monthKeys, true)) {
                $ids[] = (string) $journal['id'];
            }
        });

        return $ids;
    }

    // ---------------------------------------------------------------- reads used by central-mode reports

    /**
     * Run all reads of one central report in a single transaction (REPEATABLE READ on
     * MySQL/MariaDB/PostgreSQL) so a concurrently committing sync batch is seen entirely or not at all.
     */
    public function readConsistently(callable $callback): mixed
    {
        $db = $this->db();
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

    /** Snapshot rows for one month keyed by account id (ending_balance etc. as stored). */
    public function monthlyBalances(string $tenantId, int $year, int $month): Collection
    {
        return $this->db()->table($this->table('monthly_balances'))->where('tenant_id', $tenantId)
            ->where('fiscal_year', $year)->where('fiscal_month', $month)
            ->get(['account_id', 'opening_balance', 'total_debit', 'total_credit', 'ending_balance', 'journal_count'])
            ->keyBy('account_id');
    }

    public function fiscalPeriods(string $tenantId): Collection
    {
        return $this->hydrate(new FiscalPeriod, $this->periodRows($tenantId));
    }

    public function snapshotCounts(string $tenantId): Collection
    {
        return $this->db()->table($this->table('monthly_balances'))->where('tenant_id', $tenantId)
            ->selectRaw('fiscal_year, fiscal_month, COUNT(*) as snapshot_count')
            ->groupBy('fiscal_year', 'fiscal_month')->get();
    }

    public function earliestPostedDate(string $tenantId): mixed
    {
        return $this->db()->table($this->table('journal_entries'))->where('tenant_id', $tenantId)
            ->where('status', JournalStatus::POSTED->value)->min('trx_date');
    }

    /** Same predicate as ConsolidatedReportService::legacySignedSnapshotMonths() on the copied rows. */
    public function legacySignedSnapshotMonths(string $tenantId, array $debitNormalAccountIds): Collection
    {
        return collect($debitNormalAccountIds)->chunk(500)->flatMap(fn (Collection $ids) => $this->db()
            ->table($this->table('monthly_balances'))
            ->where('tenant_id', $tenantId)
            ->whereIn('account_id', $ids->values()->all())
            ->whereRaw('ABS(total_debit - total_credit) >= 0.005')
            ->whereRaw('ABS(ending_balance - (opening_balance + total_credit - total_debit)) < 0.005')
            ->distinct()->get(['fiscal_year', 'fiscal_month'])
            ->all());
    }

    /**
     * Details joined to their headers for one tenant, with the same column names the live
     * movement queries filter on (trx_date, status, source_type, service_id, account_id, debit, credit).
     *
     * @return array{0: \Illuminate\Database\Query\Builder, 1: string, 2: string, 3: \Illuminate\Database\Grammar}
     */
    public function movementQuery(string $tenantId): array
    {
        $details = $this->table('journal_entry_details');
        $journals = $this->table('journal_entries');
        $db = $this->db();
        $query = $db->table($details)
            ->join($journals, fn ($join) => $join->on($journals.'.id', '=', $details.'.journal_entry_id')
                ->on($journals.'.tenant_id', '=', $details.'.tenant_id'))
            ->where($details.'.tenant_id', $tenantId);

        return [$query, $details, $journals, $db->getQueryGrammar()];
    }

    // ---------------------------------------------------------------- helpers

    public static function monthOf(mixed $date): ?string
    {
        return $date === null || $date === '' ? null : Carbon::parse($date)->format('Y-m');
    }

    public function encode(array $row): string
    {
        return json_encode($row, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
    }

    public function decode(string $payload): array
    {
        return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    }

    /** Rebuild models from exact source attributes, without touching any connection. */
    protected function hydrate(Model $prototype, array $rows): Collection
    {
        return $prototype->newCollection(array_map(fn (array $attributes) => $prototype->newFromBuilder($attributes), $rows));
    }

    protected function insert(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 50) as $chunk) {
            $this->db()->table($this->table($table))->insert($chunk);
        }
    }

    protected function leaseExpiry(): string
    {
        return now()->addSeconds(max(30, (int) config('accounting.reporting.lock_seconds', 600)))->toDateTimeString();
    }

    protected function now(): string
    {
        return now()->toDateTimeString();
    }
}
