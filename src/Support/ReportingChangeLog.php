<?php

namespace ESolution\LaravelAccounting\Support;

use ESolution\LaravelAccounting\Enums\JournalStatus;
use ESolution\LaravelAccounting\Models\FiscalPeriod;
use ESolution\LaravelAccounting\Models\JournalEntry;
use ESolution\LaravelAccounting\Models\JournalEntryDetail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Tenant reporting outbox writer. Each row identifies a changed item (journal, fiscal
 * month or local cutoff); the central sync re-reads the item's current state. Package
 * write paths record inside their own transaction, so the row commits or rolls back
 * with the change. Recording is a no-op unless accounting.reporting.change_log_enabled is
 * true and master data is shared (accounting.master_data.use_shared_database): central
 * reporting supports only shared master data, so tenant-local master installs never write
 * outbox rows and their accounting writes are unaffected.
 */
class ReportingChangeLog
{
    public const JOURNAL = 'journal';

    public const MONTH = 'month';

    public const CUTOFF = 'cutoff';

    protected int $muted = 0;

    public function enabled(): bool
    {
        return (bool) config('accounting.reporting.change_log_enabled', false)
            && app(AccountingConnectionResolver::class)->useSharedMasterDatabase();
    }

    public function table(): string
    {
        return app(AccountingTableResolver::class)->table('reporting_change_log');
    }

    /** The journal connection, so log rows share the journal transaction. */
    public function connectionName(): string
    {
        return (new JournalEntry)->getConnectionName() ?: DB::getDefaultConnection();
    }

    public static function monthKey(int|string $year, int|string $month): string
    {
        return sprintf('%04d-%02d', (int) $year, (int) $month);
    }

    public function journal(iterable|string|null $ids, string $operation, ?string $connection = null): void
    {
        $this->record(self::JOURNAL, $ids, $operation, $connection);
    }

    public function month(int|string $year, int|string $month, string $operation, ?string $connection = null): void
    {
        $this->record(self::MONTH, self::monthKey($year, $month), $operation, $connection);
    }

    public function cutoff(string $operation, ?string $connection = null): void
    {
        $this->record(self::CUTOFF, '1', $operation, $connection);
    }

    public function record(string $type, iterable|string|null $keys, string $operation, ?string $connection = null): void
    {
        if (! $this->enabled()) {
            return;
        }

        $keys = is_iterable($keys) ? collect($keys) : collect([$keys]);
        $now = now()->toDateTimeString();
        $rows = $keys->filter(fn ($key) => $key !== null && $key !== '')
            ->map(fn ($key) => (string) $key)->unique()->values()
            ->map(fn (string $key) => [
                'aggregate_type' => $type,
                'aggregate_key' => $key,
                'operation' => $operation,
                'created_at' => $now,
                'acknowledged_at' => null,
            ])->all();

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::connection($connection ?? $this->connectionName())->table($this->table())->insert($chunk);
        }
    }

    /** Suppress per-row model listeners while a service records one explicit row. */
    public function muted(callable $callback): mixed
    {
        $this->muted++;

        try {
            return $callback();
        } finally {
            $this->muted--;
        }
    }

    public function isMuted(): bool
    {
        return $this->muted > 0;
    }

    /**
     * Model listeners catch Eloquent writes outside the package services (host edits and
     * deletions). With shared master data, master rows live on another connection and never
     * cascade into tenant journal tables, so master deletions need no listener.
     * Deletions record before and after the delete: a sync that reads between the two
     * still sees a later unacknowledged row. Query-builder and raw SQL writes are not
     * observable; reconciliation detects and repairs them.
     */
    public function registerModelListeners(): void
    {
        JournalEntry::created(function (JournalEntry $journal) {
            if (! $this->isMuted()) {
                $this->journal($journal->getKey(), 'created', $this->modelConnection($journal));
            }
        });
        JournalEntry::updated(function (JournalEntry $journal) {
            $posted = $journal->wasChanged('status') && $journal->status === JournalStatus::POSTED;
            $this->journal($journal->getKey(), $posted ? 'posted' : 'updated', $this->modelConnection($journal));
        });
        foreach (['deleting', 'deleted'] as $event) {
            JournalEntry::{$event}(fn (JournalEntry $journal) => $this->journal($journal->getKey(), 'deleted', $this->modelConnection($journal)));
        }

        $detail = function (JournalEntryDetail $detail, string $operation) {
            $ids = [$detail->journal_entry_id, $detail->getOriginal('journal_entry_id')];
            $this->journal($ids, $operation, $this->modelConnection($detail));
        };
        JournalEntryDetail::created(function (JournalEntryDetail $model) use ($detail) {
            if (! $this->isMuted()) {
                $detail($model, 'updated');
            }
        });
        JournalEntryDetail::updated(fn (JournalEntryDetail $model) => $detail($model, 'updated'));
        foreach (['deleting', 'deleted'] as $event) {
            JournalEntryDetail::{$event}(fn (JournalEntryDetail $model) => $detail($model, 'updated'));
        }

        // Periods are created implicitly (firstOrCreate) by journal, reversal and closing paths,
        // inside those paths' transactions. Closing/reopening record explicitly in ClosingService.
        $period = fn (FiscalPeriod $model) => $this->month($model->year, $model->month, 'opened', $this->modelConnection($model));
        FiscalPeriod::created($period);
        FiscalPeriod::deleting($period);
        FiscalPeriod::deleted($period);
    }

    protected function modelConnection(Model $model): string
    {
        return $model->getConnectionName() ?: DB::getDefaultConnection();
    }
}
