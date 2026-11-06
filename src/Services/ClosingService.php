<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Enums\JournalStatus;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\FiscalPeriod;
use ESolution\LaravelAccounting\Models\MonthlyBalance;
use ESolution\LaravelAccounting\Repositories\AccountCategoryRepository;
use ESolution\LaravelAccounting\Support\AccountingConnectionResolver;
use ESolution\LaravelAccounting\Support\AccountingTableResolver;
use ESolution\LaravelAccounting\Support\ReportingChangeLog;
use ESolution\LaravelAccounting\Support\TenantReportRunner;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClosingService
{
    public function __construct(
        protected AccountCategoryRepository $categories,
        protected AccountingTableResolver $tables
    ) {}

    protected function journalTable(): string
    {
        return $this->tables->table('journal_entries');
    }

    protected function journalDetailTable(): string
    {
        return $this->tables->table('journal_entry_details');
    }

    protected function journalTableRaw(): string
    {
        return $this->tables->connectionTable('journal_entries', $this->transactionConnection());
    }

    protected function journalDetailTableRaw(): string
    {
        return $this->tables->connectionTable('journal_entry_details', $this->transactionConnection());
    }

    protected function wrapTable(string $table): string
    {
        return DB::connection($this->transactionConnection())->getQueryGrammar()->wrapTable($table);
    }

    /**
     * Perform monthly closing.
     */
    public function closeMonth($year, $month, $userId = null)
    {
        $this->assertMonthClosingDue($year, $month);

        return app(FiscalPeriodService::class)->withClosingLock(fn () => $this->closeMonthlyPeriod($year, $month, $userId));
    }

    /** Execution eligibility never changes the selected calendar month's boundaries. */
    protected function monthlyClosingDate($year, $month): ?Carbon
    {
        $day = config('accounting.closing.closing_day');
        if ($day === null || $day === '') {
            return null;
        }
        $dayMonth = config('accounting.closing.closing_day_month');
        $dayMonth = $dayMonth === null || $dayMonth === '' ? 'next' : $dayMonth;
        validator(['closing_day' => $day, 'closing_day_month' => $dayMonth, 'year' => $year, 'month' => $month], [
            'closing_day' => ['required', function ($attribute, $value, $fail) {
                if ($value === 'month_end') {
                    return;
                }
                if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 31]]) === false) {
                    $fail('The '.$attribute.' must be an integer between 1 and 31, or month_end.');
                }
            }],
            'closing_day_month' => ['required', 'string', 'in:current,next'],
            'year' => ['required', 'integer', 'between:1,9999'],
            'month' => ['required', 'integer', 'between:1,12'],
        ])->validate();

        // The configured day belongs to the selected month itself ('current') or the month after it ('next').
        $target = Carbon::createMidnightDate((int) $year, (int) $month, 1);
        if ($dayMonth === 'next') {
            $target->addMonth();
        }

        return $target->day($day === 'month_end' ? $target->daysInMonth : min((int) $day, $target->daysInMonth));
    }

    protected function assertMonthClosingDue($year, $month): void
    {
        $due = $this->monthlyClosingDate($year, $month);
        if ($due !== null && now()->lt($due)) {
            throw ValidationException::withMessages([
                'closing_day' => ['The selected month cannot close before '.$due->toDateString().'.'],
            ]);
        }
    }

    protected function closeMonthlyPeriod($year, $month, $userId)
    {
        return DB::connection($this->transactionConnection())->transaction(function () use ($year, $month, $userId) {
            app(FiscalPeriodService::class)->lockClosingCutoff();
            app(FiscalPeriodService::class)->ensureForDate(Carbon::create($year, $month, 1));

            // 1. Validate fiscal period exists
            $period = FiscalPeriod::where('year', $year)
                ->where('month', $month)
                ->first();

            if (! $period) {
                throw new Exception("Fiscal period {$year}-{$month} not found");
            }

            if ($period->is_closed) {
                throw new Exception("Fiscal period {$year}-{$month} is already closed");
            }

            // 2. Validate journals are balanced
            $journalTable = $this->journalTable();
            $detailTable = $this->journalDetailTable();
            $journalTableWrapped = $this->wrapTable($journalTable);
            $detailTableWrapped = $this->wrapTable($detailTable);

            $totals = DB::table($journalTable)
                ->join($detailTable, "{$journalTable}.id", '=', "{$detailTable}.journal_entry_id")
                ->whereYear("{$journalTable}.trx_date", $year)
                ->whereMonth("{$journalTable}.trx_date", $month)
                ->where("{$journalTable}.status", JournalStatus::POSTED)
                ->select(
                    DB::raw("SUM({$detailTableWrapped}.debit) as total_debit"),
                    DB::raw("SUM({$detailTableWrapped}.credit) as total_credit")
                )
                ->first();

            $totalDebit = $totals->total_debit ?? 0;
            $totalCredit = $totals->total_credit ?? 0;

            if (round($totalDebit, 2) !== round($totalCredit, 2)) {
                throw new Exception("Journals are not balanced for the period {$year}-{$month}. Total Debit: {$totalDebit}, Total Credit: {$totalCredit}");
            }

            // 3. Get all accounts with their category types
            $accounts = Account::query()->get();
            $categoryTypes = $this->categories->allOrdered()->pluck('type', 'id');

            // 4. Get opening balances from previous month
            $prevMonth = $month - 1;
            $prevYear = $year;
            if ($prevMonth == 0) {
                $prevMonth = 12;
                $prevYear = $year - 1;
            }

            $prevBalances = MonthlyBalance::where('fiscal_year', $prevYear)
                ->where('fiscal_month', $prevMonth)
                ->pluck('ending_balance', 'account_id');

            // 5. Get current month activity grouped by account
            $activities = DB::table($journalTable)
                ->join($detailTable, "{$journalTable}.id", '=', "{$detailTable}.journal_entry_id")
                ->whereYear("{$journalTable}.trx_date", $year)
                ->whereMonth("{$journalTable}.trx_date", $month)
                ->where("{$journalTable}.status", JournalStatus::POSTED)
                ->groupBy("{$detailTable}.account_id")
                ->select(
                    "{$detailTable}.account_id",
                    DB::raw("SUM({$detailTableWrapped}.debit) as debit"),
                    DB::raw("SUM({$detailTableWrapped}.credit) as credit"),
                    DB::raw("COUNT(DISTINCT {$journalTableWrapped}.id) as journal_count")
                )
                ->get()
                ->keyBy('account_id');

            // 6. Calculate and Upsert Monthly Balances
            foreach ($accounts as $account) {
                $opening = $prevBalances[$account->id] ?? 0;
                $activity = $activities[$account->id] ?? (object) ['debit' => 0, 'credit' => 0, 'journal_count' => 0];

                $debit = $activity->debit ?? 0;
                $credit = $activity->credit ?? 0;

                // Determine normal balance from category type. AccountCategory exposes
                // types uppercase; compare case-insensitively so ASSET/EXPENSE snapshots
                // are stored debit-positive like every report reader expects.
                $type = strtoupper((string) $categoryTypes->get($account->category_id, 'ASSET'));
                $isDebitNormal = in_array($type, ['ASSET', 'EXPENSE'], true);

                if ($isDebitNormal) {
                    $ending = $opening + $debit - $credit;
                } else {
                    $ending = $opening + $credit - $debit;
                }

                MonthlyBalance::updateOrCreate(
                    [
                        'fiscal_year' => $year,
                        'fiscal_month' => $month,
                        'account_id' => $account->id,
                    ],
                    [
                        'opening_balance' => $opening,
                        'total_debit' => $debit,
                        'total_credit' => $credit,
                        'ending_balance' => $ending,
                        'journal_count' => $activity->journal_count ?? 0,
                        'closed_at' => now(),
                        'closed_by' => $userId,
                    ]
                );
            }

            // 7. Mark period as closed
            $period->update([
                'is_closed' => true,
                'closed_at' => now(),
                'closed_by' => $userId,
            ]);

            // One outbox row per month: the sync replaces the whole month's snapshot set.
            app(ReportingChangeLog::class)->month($year, $month, 'closed', $this->transactionConnection());

            $this->clearCache();

            return $period;
        });
    }

    /**
     * Close all open fiscal periods up to the current month.
     */
    public function closeThroughCurrentMonth($userId = null, ?string $cutoffDate = null)
    {
        if ($cutoffDate !== null) {
            return $this->closeThroughDate($cutoffDate, $userId);
        }
        // Latest month whose execution date has arrived; null closing_day keeps the current month.
        $lastMonth = now()->startOfMonth();
        while (($due = $this->monthlyClosingDate($lastMonth->year, $lastMonth->month)) !== null && now()->lt($due)) {
            $lastMonth->subMonth();
        }
        if (config('accounting.closing.cutoff_enabled', false)
            && app(FiscalPeriodService::class)->cutoffMode() === 'shared') {
            return $this->closeThroughDate($lastMonth->copy()->endOfMonth()->toDateString(), $userId);
        }

        return DB::connection($this->transactionConnection())->transaction(function () use ($userId, $lastMonth) {
            $periods = app(FiscalPeriodService::class)->ensureThroughCurrentMonth();

            $periods = $periods->filter(fn (FiscalPeriod $period) => ! $period->is_closed
                && ($period->year * 12 + $period->month) <= ($lastMonth->year * 12 + $lastMonth->month));

            if ($periods->isEmpty()) {
                return collect();
            }

            $closedPeriods = collect();

            foreach ($periods as $period) {
                $closedPeriods->push($this->closeMonth($period->year, $period->month, $userId));
            }

            return $closedPeriods;
        });
    }

    /**
     * Alias for closing all open periods up to the current month.
     */
    public function closeUntilCurrentMonth($userId = null, ?string $cutoffDate = null)
    {
        return $this->closeThroughCurrentMonth($userId, $cutoffDate);
    }

    public function closeThroughDate(string $cutoffDate, int|string|null $userId = null, ?string $reason = null, ?array $tenantIds = null): array
    {
        if (config('accounting.closing.cutoff_enabled', false) && app(FiscalPeriodService::class)->cutoffMode() === 'shared') {
            return $this->changeSharedCutoff($cutoffDate, false, $reason, $userId, $tenantIds);
        }
        if ($tenantIds !== null) {
            return app(TenantReportRunner::class)->run($tenantIds, fn () => $this->changeCutoff($cutoffDate, false, $reason, $userId));
        }
        return $this->changeCutoff($cutoffDate, false, $reason, $userId);
    }

    /** Retain closure through an earlier date, or clear the cutoff with null. */
    public function reopenCutoff(?string $cutoffDate, string $reason, int|string|null $userId = null, ?array $tenantIds = null): array
    {
        if (config('accounting.closing.cutoff_enabled', false) && app(FiscalPeriodService::class)->cutoffMode() === 'shared') {
            return $this->changeSharedCutoff($cutoffDate, true, $reason, $userId, $tenantIds);
        }
        if ($tenantIds !== null) {
            return app(TenantReportRunner::class)->run($tenantIds, fn () => $this->changeCutoff($cutoffDate, true, $reason, $userId));
        }
        return $this->changeCutoff($cutoffDate, true, $reason, $userId);
    }

    public function getCutoffDate(): ?string
    {
        return app(FiscalPeriodService::class)->lockClosingCutoff()?->cutoff_date;
    }

    public function getCutoffHistory(): Collection
    {
        $this->requireCutoffEnabled();

        $periods = app(FiscalPeriodService::class);
        $shared = $periods->cutoffMode() === 'shared';

        return DB::connection($periods->cutoffConnection())
            ->table($this->tables->table($shared ? 'shared_closing_cutoff_events' : 'closing_cutoff_events'))
            ->orderBy('id')->get()->map(function ($event) use ($shared) {
                if ($shared) {
                    $event->tenant_ids = json_decode($event->tenant_ids, true, 512, JSON_THROW_ON_ERROR);
                }

                return $event;
            });
    }

    protected function requireCutoffEnabled(): void
    {
        if (! config('accounting.closing.cutoff_enabled', false)) {
            throw ValidationException::withMessages([
                'cutoff_enabled' => ['Enable accounting.closing.cutoff_enabled after migrating the transaction database.'],
            ]);
        }
    }

    protected function changeCutoff(?string $date, bool $reopen, ?string $reason, int|string|null $actor): array
    {
        $actor = $this->validateCutoffInput($date, $reopen, $reason, $actor);

        $connection = $this->transactionConnection();

        return DB::connection($connection)->transaction(function () use ($connection, $date, $reopen, $reason, $actor) {
            $state = app(FiscalPeriodService::class)->lockClosingCutoff();
            $previous = $state->cutoff_date;
            if ($reopen) {
                if ($previous === null || ($date !== null && $date >= $previous)) {
                    throw ValidationException::withMessages(['cutoff_date' => ['Reopening requires an active cutoff and an earlier date, or null to clear it.']]);
                }
                $this->validateCutoffPeriods($date, true);
            } else {
                if ($previous !== null && $date <= $previous) {
                    throw ValidationException::withMessages(['cutoff_date' => ['A closing cutoff must advance beyond the saved date. Use reopenCutoff() to move it backward.']]);
                }
                $this->validateCutoffPeriods($date, false);
            }

            $event = [
                'action' => $reopen ? 'reopen' : ($previous === null ? 'close' : 'advance'),
                'previous_cutoff_date' => $previous,
                'cutoff_date' => $date,
                'actor_id' => $actor,
                'reason' => $reason,
                'created_at' => now()->toDateTimeString(),
            ];
            DB::connection($connection)->table($this->tables->table('closing_cutoffs'))->where('id', 1)
                ->update(['cutoff_date' => $date, 'updated_at' => $event['created_at']]);
            $id = DB::connection($connection)->table($this->tables->table('closing_cutoff_events'))->insertGetId($event);
            app(ReportingChangeLog::class)->cutoff($event['action'], $connection);
            $this->clearCache();

            return ['id' => $id] + $event;
        });
    }

    protected function validateCutoffInput(?string $date, bool $reopen, ?string $reason, int|string|null $actor): ?string
    {
        $this->requireCutoffEnabled();
        $actor = $actor === null ? null : trim((string) $actor);
        validator(['cutoff_date' => $date, 'reason' => $reason, 'actor_id' => $actor], [
            'cutoff_date' => [$reopen ? 'nullable' : 'required', 'date_format:Y-m-d'],
            'reason' => [$reopen ? 'required' : 'nullable', 'string', 'max:1000'],
            'actor_id' => ['nullable', 'string', 'max:100'],
        ])->validate();

        return $actor;
    }

    protected function validateCutoffPeriods(?string $date, bool $reopen): void
    {
        $connection = $this->transactionConnection();
        if ($reopen) {
            $closed = FiscalPeriod::on($connection)->where('is_closed', true);
            if ($date !== null) {
                $closed->whereDate('end_date', '>', $date);
            }
            if ($closed->exists()) {
                throw ValidationException::withMessages(['cutoff_date' => ['Reopen overlapping or later closed fiscal months first, latest month first.']]);
            }

            return;
        }

        $journals = $this->journalTable();
        $details = $this->journalDetailTable();
        $db = DB::connection($connection);
        $grammar = $db->getQueryGrammar();
        $totals = $db->table($journals)
            ->join($details, $details.'.journal_entry_id', '=', $journals.'.id')
            ->whereDate($journals.'.trx_date', '<=', $date)
            ->where($journals.'.status', JournalStatus::POSTED->value)
            ->selectRaw('SUM('.$grammar->wrap($details.'.debit').') as debit')
            ->selectRaw('SUM('.$grammar->wrap($details.'.credit').') as credit')->first();
        if (round((float) ($totals->debit ?? 0), 2) !== round((float) ($totals->credit ?? 0), 2)) {
            throw ValidationException::withMessages(['cutoff_date' => ['Posted journals through the cutoff are not balanced.']]);
        }
    }

    protected function changeSharedCutoff(?string $date, bool $reopen, ?string $reason, int|string|null $actor, ?array $tenantIds): array
    {
        $actor = $this->validateCutoffInput($date, $reopen, $reason, $actor);
        $periods = app(FiscalPeriodService::class);

        return $periods->withClosingLock(function () use ($periods, $date, $reopen, $reason, $actor, $tenantIds) {
            $state = $periods->lockClosingCutoff();
            $savedIds = $state->tenant_ids === null ? null : json_decode($state->tenant_ids, true, 512, JSON_THROW_ON_ERROR);
            // Participants come from the explicit argument, otherwise the persisted scope.
            if ($tenantIds === null && $savedIds === null) {
                throw ValidationException::withMessages(['tenant_ids' => ['Supply tenantIds for the first shared close; later calls reuse the saved shared scope.']]);
            }
            $runner = app(TenantReportRunner::class);
            $ids = array_column($runner->run($tenantIds ?? $savedIds, function () use ($periods) {
                if ($this->transactionConnection() === $periods->cutoffConnection()) {
                    throw new \RuntimeException('Shared closing requires the master connection to be separate from tenant transaction connections.');
                }
                if (DB::connection($this->transactionConnection())->transactionLevel() !== 0) {
                    throw new \RuntimeException('Shared closing cannot run inside a caller-owned tenant transaction.');
                }
            }), 'tenant_id');
            if ($savedIds !== null && (array_diff($savedIds, $ids) || array_diff($ids, $savedIds))) {
                throw ValidationException::withMessages(['tenant_ids' => ['Supply the entire shared closing scope; its membership cannot change implicitly.']]);
            }

            $previous = $state->cutoff_date;
            // A completed retry returns the original audit event, without closing twice.
            if ($savedIds !== null && $previous === $date) {
                $event = DB::connection($periods->cutoffConnection())
                    ->table($this->tables->table('shared_closing_cutoff_events'))->orderByDesc('id')->first();
                if ($event && ($event->action === 'reopen') === $reopen) {
                    $event->tenant_ids = json_decode($event->tenant_ids, true, 512, JSON_THROW_ON_ERROR);

                    return (array) $event;
                }
            }
            if (($reopen && ($previous === null || ($date !== null && $date >= $previous)))
                || (! $reopen && $previous !== null && $date <= $previous)) {
                throw ValidationException::withMessages(['cutoff_date' => ['Advance the saved cutoff, or use reopenCutoff() with an earlier date.']]);
            }

            // Validate every database before any monthly snapshot or shared state change.
            $runner->run($ids, function () use ($date, $reopen, $actor) {
                $this->validateCutoffPeriods($date, $reopen);
                if (! $reopen) {
                    $this->closeCompleteMonths($date, $actor, true);
                }
            });
            if (! $reopen) {
                $runner->run($ids, fn () => $this->closeCompleteMonths($date, $actor));
            }

            $event = [
                'action' => $reopen ? 'reopen' : ($previous === null ? 'close' : 'advance'),
                'previous_cutoff_date' => $previous, 'cutoff_date' => $date,
                'tenant_ids' => json_encode($ids, JSON_THROW_ON_ERROR),
                'actor_id' => $actor, 'reason' => $reason, 'created_at' => now()->toDateTimeString(),
            ];
            $db = DB::connection($periods->cutoffConnection());
            $db->table($this->tables->table('shared_closing_cutoffs'))->where('id', 1)->update([
                'cutoff_date' => $date, 'tenant_ids' => $event['tenant_ids'], 'updated_at' => $event['created_at'],
            ]);
            $id = $db->table($this->tables->table('shared_closing_cutoff_events'))->insertGetId($event);
            $event['tenant_ids'] = $ids;

            return ['id' => $id] + $event;
        });
    }

    protected function closeCompleteMonths(string $date, int|string|null $actor, bool $validateOnly = false): void
    {
        $db = DB::connection($this->transactionConnection());
        $earliest = array_filter([
            $db->table($this->journalTable())->min('trx_date'),
            FiscalPeriod::query()->min('start_date'),
        ]);
        $start = Carbon::parse($earliest ? min($earliest) : $date)->startOfMonth();
        $end = Carbon::parse($date);
        while ($start->copy()->endOfMonth()->toDateString() <= $end->toDateString()) {
            if (! FiscalPeriod::where('year', $start->year)->where('month', $start->month)->where('is_closed', true)->exists()) {
                if ($validateOnly) {
                    $this->assertMonthClosingDue($start->year, $start->month);
                } else {
                    $this->closeMonth($start->year, $start->month, $actor);
                }
            }
            $start->addMonth();
        }
    }

    /**
     * Reopen a closed month.
     */
    public function reopenMonth($year, $month, $userId = null)
    {
        return app(FiscalPeriodService::class)->withClosingLock(fn () => $this->reopenMonthlyPeriod($year, $month));
    }

    protected function reopenMonthlyPeriod($year, $month)
    {
        return DB::connection($this->transactionConnection())->transaction(function () use ($year, $month) {
            app(FiscalPeriodService::class)->lockClosingCutoff();
            $period = FiscalPeriod::where('year', $year)
                ->where('month', $month)
                ->first();

            if (! $period) {
                throw new Exception("Fiscal period {$year}-{$month} not found");
            }

            if (! $period->is_closed) {
                throw new Exception("Fiscal period {$year}-{$month} is not closed");
            }

            // Check if any subsequent months are closed
            $subsequentClosed = FiscalPeriod::where(function ($query) use ($year, $month) {
                $query->where('year', '>', $year)
                    ->orWhere(function ($q) use ($year, $month) {
                        $q->where('year', $year)->where('month', '>', $month);
                    });
            })->where('is_closed', true)->exists();

            if ($subsequentClosed) {
                throw new Exception('Cannot reopen this month because subsequent months are already closed. Please reopen them first.');
            }

            $period->update([
                'is_closed' => false,
                'closed_at' => null,
                'closed_by' => null,
            ]);

            // Snapshots are retained (stale semantics); the sync copies the reopened month as-is.
            app(ReportingChangeLog::class)->month($year, $month, 'reopened', $this->transactionConnection());

            $this->clearCache();

            return $period;
        });
    }

    protected function transactionConnection(): ?string
    {
        return app(AccountingConnectionResolver::class)->resolveTransactionDataConnection();
    }

    protected function clearCache(): void
    {
        $tags = ['acc_journals'];
        $tenantId = $this->getTenantId();

        if ($tenantId) {
            $tags[] = 'acc_journals_tenant_'.$tenantId;
        }

        Cache::tags($tags)->flush();
    }

    protected function getTenantId(): ?string
    {
        if (function_exists('tenancy') && tenancy()->initialized) {
            return tenancy()->tenant->id;
        }

        return null;
    }
}
