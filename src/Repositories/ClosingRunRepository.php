<?php

namespace ESolution\LaravelAccounting\Repositories;

use ESolution\LaravelAccounting\Support\AccountingConnectionResolver;
use ESolution\LaravelAccounting\Support\AccountingTableResolver;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Global monthly-closing and -reopening runs (operation close|reopen), their per-tenant items and per tenant/period leases, stored on the
 * master connection (accounting.master_data.connection). Writes are short and never held open
 * while a tenant closes, so tenant and shared-cutoff locks are never nested inside them.
 */
class ClosingRunRepository
{
    public const RUN_STATUSES = ['pending', 'processing', 'completed', 'partially_failed', 'failed'];

    public const TENANT_STATUSES = ['pending', 'processing', 'closed', 'already_closed', 'not_due', 'failed'];

    /** Tenant statuses of a reopen run (ClosingService::reopenMonthGlobally()). */
    public const REOPEN_TENANT_STATUSES = ['pending', 'processing', 'reopened', 'already_open', 'failed'];

    public const OPERATIONS = ['close', 'reopen'];

    public function __construct(
        protected AccountingConnectionResolver $connections,
        protected AccountingTableResolver $tables
    ) {}

    public function connectionName(): string
    {
        $name = $this->connections->masterConnectionName();
        if ($name === null || ! config('database.connections.'.$name)) {
            throw new RuntimeException('Global monthly closing requires accounting.master_data.connection (ACCOUNTING_MASTER_CONNECTION) to name a configured central connection.');
        }

        return $name;
    }

    /** $reopen also requires the operation columns (2026_11_11_000021); closing works without them. */
    public function assertUsable(bool $reopen = false): string
    {
        $connection = $this->connectionName();
        if (! Schema::connection($connection)->hasTable($this->table('runs'))) {
            throw new RuntimeException('Global monthly closing tables are missing on connection ['.$connection.']. Run the accounting migrations against that connection (php artisan migrate --database='.$connection.').');
        }
        if ($reopen && ! Schema::connection($connection)->hasColumn($this->table('runs'), 'operation')) {
            throw new RuntimeException('Global monthly reopening columns are missing on connection ['.$connection.']. Run the accounting migrations against that connection (php artisan migrate --database='.$connection.').');
        }

        return $connection;
    }

    /** A run's operation; rows written before the operation column existed are close runs. */
    public static function operation(object $run): string
    {
        return ($run->operation ?? null) === 'reopen' ? 'reopen' : 'close';
    }

    public function db(): ConnectionInterface
    {
        return DB::connection($this->connectionName());
    }

    public function table(string $name): string
    {
        return $this->tables->table($name === 'runs' ? 'closing_runs' : 'closing_run_'.$name);
    }

    /**
     * Insert the run and one pending item per tenant atomically; returns the run id. Close runs
     * leave operation to its column default, so closing keeps working before 2026_11_11_000021.
     */
    public function createRun(int $year, int $month, array $tenantIds, ?string $actorId, string $trigger, string $operation = 'close', ?string $reason = null): int
    {
        return $this->db()->transaction(function () use ($year, $month, $tenantIds, $actorId, $trigger, $operation, $reason) {
            $now = $this->now();
            $runId = (int) $this->db()->table($this->table('runs'))->insertGetId([
                'year' => $year, 'month' => $month, 'trigger' => $trigger, 'status' => 'pending', 'actor_id' => $actorId,
                'tenant_ids' => json_encode(array_values($tenantIds), JSON_THROW_ON_ERROR), 'tenant_count' => count($tenantIds),
                'created_at' => $now, 'updated_at' => $now,
            ] + ($operation === 'close' ? [] : ['operation' => $operation, 'reason' => $reason]));
            foreach (array_chunk($tenantIds, 500) as $chunk) {
                $this->db()->table($this->table('tenants'))->insert(array_map(fn (string $tenantId) => [
                    'run_id' => $runId, 'tenant_id' => $tenantId, 'status' => 'pending', 'attempts' => 0,
                    'created_at' => $now, 'updated_at' => $now,
                ], $chunk));
            }

            return $runId;
        });
    }

    public function run(int $runId): ?object
    {
        return $this->db()->table($this->table('runs'))->where('id', $runId)->first();
    }

    public function item(int $itemId): ?object
    {
        return $this->db()->table($this->table('tenants'))->where('id', $itemId)->first();
    }

    public function items(int $runId): Collection
    {
        return $this->db()->table($this->table('tenants'))->where('run_id', $runId)->orderBy('id')->get();
    }

    public function latestRun(int $year, int $month, string $trigger): ?object
    {
        return $this->db()->table($this->table('runs'))->where('year', $year)->where('month', $month)
            ->where('trigger', $trigger)->orderByDesc('id')->first();
    }

    public function unfinishedRunIds(): array
    {
        return $this->db()->table($this->table('runs'))->whereIn('status', ['pending', 'processing'])
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Items that need a (new) job: stale processing claims, and pending items queued longer than
     * one lease ago (a lost or never-run job). Recently queued pending items keep their job.
     */
    public function recoverableItemIds(int $runId, int $leaseSeconds): array
    {
        $now = $this->now();
        $queuedBefore = now()->subSeconds($leaseSeconds)->toDateTimeString();

        return $this->db()->table($this->table('tenants'))->where('run_id', $runId)
            ->where(fn ($query) => $query->where(fn ($q) => $q->where('status', 'pending')->where('updated_at', '<=', $queuedBefore))
                ->orWhere(fn ($q) => $q->where('status', 'processing')->where('claimed_until', '<', $now)))
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Claim an item for one attempt. Takes the tenant/period lease first (shared by every run),
     * then flips the item from pending, or from an expired processing claim, to processing.
     *
     * @return array{0: ?string, 1: ?string} [token, refusal reason: busy|not_claimable]
     */
    public function claim(object $item, object $run, int $leaseSeconds): array
    {
        $db = $this->db();
        $now = $this->now();
        $expires = now()->addSeconds($leaseSeconds)->toDateTimeString();
        $token = (string) Str::uuid();
        $claimable = fn ($query) => $query->where('status', 'pending')
            ->orWhere(fn ($q) => $q->where('status', 'processing')->where('claimed_until', '<', $now));

        if (! $db->table($this->table('tenants'))->where('id', $item->id)->where($claimable)->exists()) {
            return [null, 'not_claimable'];
        }
        $key = ['tenant_id' => $item->tenant_id, 'year' => $run->year, 'month' => $run->month];
        $db->table($this->table('locks'))->insertOrIgnore($key);
        $leased = $db->table($this->table('locks'))->where($key)
            ->where(fn ($query) => $query->whereNull('token')->orWhere('expires_at', '<', $now))
            ->update(['item_id' => $item->id, 'token' => $token, 'expires_at' => $expires]) === 1;
        if (! $leased) {
            return [null, 'busy'];
        }

        $claimed = $db->table($this->table('tenants'))->where('id', $item->id)->where($claimable)->increment('attempts', 1, [
            'status' => 'processing', 'claim_token' => $token, 'claimed_until' => $expires, 'started_at' => $now,
            'finished_at' => null, 'error_code' => null, 'error_params' => null, 'error_reference' => null, 'updated_at' => $now,
        ]) === 1;
        if (! $claimed) {
            $this->releaseLease($item->tenant_id, (int) $run->year, (int) $run->month, $token);

            return [null, 'not_claimable'];
        }
        $db->table($this->table('runs'))->where('id', $run->id)->whereNull('started_at')->update(['started_at' => $now]);
        $db->table($this->table('runs'))->where('id', $run->id)->where('status', 'pending')->update(['status' => 'processing', 'updated_at' => $now]);

        return [$token, null];
    }

    /** Record an outcome only while this worker still owns the claim (fencing). */
    public function finish(int $itemId, string $token, array $values): bool
    {
        return $this->db()->table($this->table('tenants'))->where('id', $itemId)->where('claim_token', $token)
            ->update($values + ['claim_token' => null, 'claimed_until' => null, 'finished_at' => $this->now(), 'updated_at' => $this->now()]) === 1;
    }

    public function releaseLease(string $tenantId, int $year, int $month, string $token): void
    {
        $this->db()->table($this->table('locks'))->where('tenant_id', $tenantId)->where('year', $year)->where('month', $month)
            ->where('token', $token)->update(['item_id' => null, 'token' => null, 'expires_at' => null]);
    }

    /** Whether another run already closed this tenant/period (it was closed, then reopened). */
    public function closedInAnotherRun(string $tenantId, int $year, int $month, int $runId): bool
    {
        $runs = $this->table('runs');
        $items = $this->table('tenants');

        return $this->db()->table($items)->join($runs, $runs.'.id', '=', $items.'.run_id')
            ->where($items.'.tenant_id', $tenantId)->where($items.'.status', 'closed')->where($items.'.run_id', '!=', $runId)
            ->where($runs.'.year', $year)->where($runs.'.month', $month)->exists();
    }

    /** Whether a global reopen run reopened this tenant/period. */
    public function reopenedByRun(string $tenantId, int $year, int $month): bool
    {
        $runs = $this->table('runs');
        $items = $this->table('tenants');

        return $this->db()->table($items)->join($runs, $runs.'.id', '=', $items.'.run_id')
            ->where($items.'.tenant_id', $tenantId)->where($items.'.status', 'reopened')
            ->where($runs.'.year', $year)->where($runs.'.month', $month)->exists();
    }

    /**
     * The latest finished close or reopen outcome per tenant for one period across every run, so a
     * historical run can show what happened to its periods afterwards without changing its own rows.
     * Only global runs are tracked; direct closeMonth()/reopenMonth() calls are not.
     *
     * @return array<string, array{state: string, run_id: int, operation: string, status: string, finished_at: ?string}>
     */
    public function latestPeriodOutcomes(array $tenantIds, int $year, int $month): array
    {
        $runs = $this->table('runs');
        $items = $this->table('tenants');
        $latest = [];
        foreach (array_chunk(array_values(array_unique($tenantIds)), 500) as $chunk) {
            $rows = $this->db()->table($items)->join($runs, $runs.'.id', '=', $items.'.run_id')
                ->where($runs.'.year', $year)->where($runs.'.month', $month)->whereIn($items.'.tenant_id', $chunk)
                ->whereIn($items.'.status', ['closed', 'already_closed', 'reopened', 'already_open'])
                ->orderBy($items.'.finished_at')->orderBy($items.'.id')
                ->get([$items.'.tenant_id', $items.'.run_id', $items.'.status', $items.'.finished_at']);
            foreach ($rows as $row) {
                $open = in_array($row->status, ['reopened', 'already_open'], true);
                $latest[$row->tenant_id] = [
                    'state' => $open ? 'open' : 'closed',
                    'run_id' => (int) $row->run_id,
                    'operation' => $open ? 'reopen' : 'close',
                    'status' => $row->status,
                    'finished_at' => $row->finished_at,
                ];
            }
        }

        return $latest;
    }

    /**
     * Return failed items, and processing items whose claim expired, to pending.
     *
     * @return list<int> item ids to dispatch
     */
    public function resetForRetry(int $runId, ?array $tenantIds = null): array
    {
        return $this->db()->transaction(function () use ($runId, $tenantIds) {
            $now = $this->now();
            $query = $this->db()->table($this->table('tenants'))->where('run_id', $runId)
                ->where(fn ($q) => $q->where('status', 'failed')
                    ->orWhere(fn ($q) => $q->where('status', 'processing')->where('claimed_until', '<', $now)))
                ->when($tenantIds !== null, fn ($q) => $q->whereIn('tenant_id', $tenantIds));
            $ids = (clone $query)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($ids !== []) {
                $this->db()->table($this->table('tenants'))->whereIn('id', $ids)->update([
                    'status' => 'pending', 'claim_token' => null, 'claimed_until' => null, 'error_code' => null,
                    'error_params' => null, 'error_reference' => null, 'finished_at' => null, 'updated_at' => $now,
                ]);
            }
            $this->refreshRun($runId);

            return $ids;
        });
    }

    /** Recompute counts and status from the items under a run row lock, so concurrent workers converge. */
    public function refreshRun(int $runId): void
    {
        $this->db()->transaction(function () use ($runId) {
            $run = $this->db()->table($this->table('runs'))->where('id', $runId)->lockForUpdate()->first();
            if (! $run) {
                return;
            }
            $counts = $this->db()->table($this->table('tenants'))->where('run_id', $runId)
                ->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')
                ->map(fn ($count) => (int) $count);
            $active = ($counts['pending'] ?? 0) + ($counts['processing'] ?? 0);
            $failed = $counts['failed'] ?? 0;
            $total = (int) $run->tenant_count;
            $status = match (true) {
                $active > 0 => $run->started_at === null ? 'pending' : 'processing',
                $failed === 0 => 'completed',
                $failed === $total => 'failed',
                default => 'partially_failed',
            };
            $now = $this->now();
            $reopen = self::operation($run) === 'reopen' ? [
                'reopened_count' => $counts['reopened'] ?? 0,
                'already_open_count' => $counts['already_open'] ?? 0,
            ] : [];
            $this->db()->table($this->table('runs'))->where('id', $runId)->update([
                'status' => $status,
                'closed_count' => $counts['closed'] ?? 0,
                'already_closed_count' => $counts['already_closed'] ?? 0,
                'not_due_count' => $counts['not_due'] ?? 0,
                'failed_count' => $failed,
                'finished_at' => $active > 0 ? null : ($run->finished_at ?? $now),
                'updated_at' => $now,
            ] + $reopen);
        });
    }

    protected function now(): string
    {
        return now()->toDateTimeString();
    }
}
