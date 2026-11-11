<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Enums\JournalStatus;
use ESolution\LaravelAccounting\Exceptions\ClosingPeriodException;
use ESolution\LaravelAccounting\Jobs\CloseTenantMonthJob;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\FiscalPeriod;
use ESolution\LaravelAccounting\Models\MonthlyBalance;
use ESolution\LaravelAccounting\Repositories\AccountCategoryRepository;
use ESolution\LaravelAccounting\Repositories\ClosingRunRepository;
use ESolution\LaravelAccounting\Support\AccountingConnectionResolver;
use ESolution\LaravelAccounting\Support\AccountingErrorReporter;
use ESolution\LaravelAccounting\Support\AccountingTableResolver;
use ESolution\LaravelAccounting\Support\ReportingChangeLog;
use ESolution\LaravelAccounting\Support\TenantReportRunner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use Throwable;

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
                throw new ClosingPeriodException(ClosingPeriodException::NOT_FOUND, "Fiscal period {$year}-{$month} not found");
            }

            if ($period->is_closed) {
                throw new ClosingPeriodException(ClosingPeriodException::ALREADY_CLOSED, "Fiscal period {$year}-{$month} is already closed");
            }

            // 2. Validate journals are balanced
            $journalTable = $this->journalTable();
            $detailTable = $this->journalDetailTable();
            $journalTableWrapped = $this->wrapTable($journalTable);
            $detailTableWrapped = $this->wrapTable($detailTable);

            $totals = $this->postedMonthTotals($year, $month);

            $totalDebit = $totals->total_debit ?? 0;
            $totalCredit = $totals->total_credit ?? 0;

            if (round($totalDebit, 2) !== round($totalCredit, 2)) {
                throw new ClosingPeriodException(
                    ClosingPeriodException::UNBALANCED,
                    "Journals are not balanced for the period {$year}-{$month}. Total Debit: {$totalDebit}, Total Credit: {$totalCredit}",
                    ['total_debit' => (float) $totalDebit, 'total_credit' => (float) $totalCredit]
                );
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

    /** Posted debit/credit totals of one calendar month on the active transaction connection. */
    protected function postedMonthTotals($year, $month): ?object
    {
        $journalTable = $this->journalTable();
        $detailTable = $this->journalDetailTable();
        $detailTableWrapped = $this->wrapTable($detailTable);

        return DB::table($journalTable)
            ->join($detailTable, "{$journalTable}.id", '=', "{$detailTable}.journal_entry_id")
            ->whereYear("{$journalTable}.trx_date", $year)
            ->whereMonth("{$journalTable}.trx_date", $month)
            ->where("{$journalTable}.status", JournalStatus::POSTED)
            ->select(
                DB::raw("SUM({$detailTableWrapped}.debit) as total_debit"),
                DB::raw("SUM({$detailTableWrapped}.credit) as total_credit")
            )
            ->first();
    }

    /** Latest month whose execution date has arrived; null closing_day keeps the current month. */
    protected function latestDueMonth(): Carbon
    {
        $lastMonth = now()->startOfMonth();
        while (($due = $this->monthlyClosingDate($lastMonth->year, $lastMonth->month)) !== null && now()->lt($due)) {
            $lastMonth->subMonth();
        }

        return $lastMonth;
    }

    /**
     * Close all open fiscal periods up to the current month.
     */
    public function closeThroughCurrentMonth($userId = null, ?string $cutoffDate = null)
    {
        if ($cutoffDate !== null) {
            return $this->closeThroughDate($cutoffDate, $userId);
        }
        $lastMonth = $this->latestDueMonth();
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

            // ClosingPeriodException extends Exception; the messages are unchanged for existing callers.
            if (! $period) {
                throw new ClosingPeriodException(ClosingPeriodException::NOT_FOUND, "Fiscal period {$year}-{$month} not found");
            }

            if (! $period->is_closed) {
                throw new ClosingPeriodException(ClosingPeriodException::NOT_CLOSED, "Fiscal period {$year}-{$month} is not closed");
            }

            // Check if any subsequent months are closed (the latest one must be reopened first)
            $subsequentClosed = FiscalPeriod::where(function ($query) use ($year, $month) {
                $query->where('year', '>', $year)
                    ->orWhere(function ($q) use ($year, $month) {
                        $q->where('year', $year)->where('month', '>', $month);
                    });
            })->where('is_closed', true)->orderByDesc('year')->orderByDesc('month')->first();

            if ($subsequentClosed) {
                throw new ClosingPeriodException(
                    ClosingPeriodException::LATER_MONTH_CLOSED,
                    'Cannot reopen this month because subsequent months are already closed. Please reopen them first.',
                    ['year' => (int) $subsequentClosed->year, 'month' => (int) $subsequentClosed->month]
                );
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

    // ------------------------------------------------------------------ global monthly closing

    /**
     * Close one calendar month in many tenants, one queued job per tenant. Null or [] selects every
     * tenant of tenancy.tenant_model; an explicit list is resolved (ids or route keys) and
     * deduplicated first. Tracking lives on the master connection; each tenant reuses closeMonth()
     * (eligibility, transactions, shared lock, outbox) and never publishes or changes a shared
     * cutoff. Scheduled calls keep one run per period: later calls resume that run instead of
     * starting another, and periods that were closed before and reopened are skipped as not_due.
     */
    public function closeMonthGlobally(int $year, int $month, ?array $tenantIds = null, int|string|null $actorId = null, bool $scheduled = false): array
    {
        $actorId = $actorId === null ? null : trim((string) $actorId);
        validator(['year' => $year, 'month' => $month, 'actor_id' => $actorId], [
            'year' => ['required', 'integer', 'between:1,9999'],
            'month' => ['required', 'integer', 'between:1,12'],
            'actor_id' => ['nullable', 'string', 'max:100'],
        ])->validate();
        $runs = $this->closingRuns();
        $runs->assertUsable();
        // Invalid closing settings fail before any run is recorded.
        $this->monthlyClosingDate($year, $month);

        if ($scheduled && ($existing = $runs->latestRun($year, $month, 'scheduled')) !== null) {
            $this->dispatchGlobalCloseItems($runs->recoverableItemIds((int) $existing->id, $this->globalCloseLeaseSeconds()));

            return $this->getGlobalCloseRun((int) $existing->id);
        }

        $ids = $this->globalRunTenantIds($tenantIds, 'close');

        $runId = $runs->createRun($year, $month, $ids, $actorId, $scheduled ? 'scheduled' : 'manual');
        $this->dispatchGlobalCloseItems($runs->items($runId)->pluck('id')->all());

        return $this->getGlobalCloseRun($runId);
    }

    /**
     * Reopen one calendar month in many tenants, one queued job per tenant, tracked like
     * closeMonthGlobally() (operation 'reopen'). Null or [] selects every tenant. Each tenant runs
     * the existing reopenMonth(): missing periods and periods with a later closed month fail,
     * snapshots are retained, caches are cleared and the reporting outbox row is written in the
     * same tenant transaction. Cutoffs are never moved; a tenant whose cutoff still covers the
     * month is reported with a cutoff_active notice (use reopenCutoff() explicitly). The reason
     * (required) and actor are stored on the run. Scheduled closing never recloses a period
     * reopened by such a run.
     */
    public function reopenMonthGlobally(int $year, int $month, ?array $tenantIds, string $reason, int|string|null $actorId = null): array
    {
        $actorId = $actorId === null ? null : trim((string) $actorId);
        $reason = trim($reason);
        validator(['year' => $year, 'month' => $month, 'reason' => $reason, 'actor_id' => $actorId], [
            'year' => ['required', 'integer', 'between:1,9999'],
            'month' => ['required', 'integer', 'between:1,12'],
            'reason' => ['required', 'string', 'max:1000'],
            'actor_id' => ['nullable', 'string', 'max:100'],
        ])->validate();
        $runs = $this->closingRuns();
        $runs->assertUsable(true);

        $ids = $this->globalRunTenantIds($tenantIds, 'reopen');
        $runId = $runs->createRun($year, $month, $ids, $actorId, 'manual', 'reopen', $reason);
        $this->dispatchGlobalCloseItems($runs->items($runId)->pluck('id')->all());

        return $this->getGlobalCloseRun($runId);
    }

    /** Null or [] means every tenant; an explicit list is resolved to canonical ids and deduplicated. */
    protected function globalRunTenantIds(?array $tenantIds, string $operation): array
    {
        $tenants = app(TenantReportRunner::class);
        $ids = $tenantIds === null || $tenantIds === []
            ? $tenants->allTenantIds()
            : array_map('strval', array_keys($tenants->resolveTenants($tenantIds)));
        if ($ids === []) {
            throw ValidationException::withMessages(['tenant_ids' => ['No tenants were found to '.$operation.'.']]);
        }

        return $ids;
    }

    /**
     * Progress and results of one close or reopen run, with localized readable messages for failed
     * and not_due tenants and cutoff notices for reopened tenants. The run's own rows are history;
     * tracked_period shows the latest close/reopen outcome of the same tenant and period across
     * every global run (null when none finished).
     */
    public function getGlobalCloseRun(int $runId): array
    {
        $runs = $this->closingRuns();
        $run = $runs->run($runId);
        if (! $run) {
            throw ValidationException::withMessages(['run_id' => ['Closing run ['.$runId.'] was not found.']]);
        }
        $operation = ClosingRunRepository::operation($run);
        $items = $runs->items($runId);
        $now = now()->toDateTimeString();
        $counts = array_fill_keys($operation === 'reopen' ? ClosingRunRepository::REOPEN_TENANT_STATUSES : ClosingRunRepository::TENANT_STATUSES, 0);
        foreach ($items as $item) {
            $counts[$item->status] = ($counts[$item->status] ?? 0) + 1;
        }
        $tracked = $runs->latestPeriodOutcomes($items->pluck('tenant_id')->all(), (int) $run->year, (int) $run->month);

        return [
            'id' => (int) $run->id,
            'operation' => $operation,
            'year' => (int) $run->year,
            'month' => (int) $run->month,
            'period' => sprintf('%04d-%02d', $run->year, $run->month),
            'trigger' => $run->trigger,
            'status' => $run->status,
            'actor_id' => $run->actor_id,
            'reason' => $run->reason ?? null,
            'tenant_ids' => json_decode($run->tenant_ids, true, 512, JSON_THROW_ON_ERROR),
            'counts' => ['total' => $items->count()] + $counts,
            'created_at' => $run->created_at,
            'started_at' => $run->started_at,
            'finished_at' => $run->finished_at,
            'tenants' => $items->map(fn ($item) => [
                'tenant_id' => $item->tenant_id,
                'status' => $item->status,
                'attempts' => (int) $item->attempts,
                'started_at' => $item->started_at,
                'finished_at' => $item->finished_at,
                'total_debit' => $item->total_debit === null ? null : (float) $item->total_debit,
                'total_credit' => $item->total_credit === null ? null : (float) $item->total_credit,
                'stale' => $item->status === 'processing' && $item->claimed_until !== null && $item->claimed_until < $now,
                'error' => $item->status === 'failed' ? $this->globalCloseMessage($item, $run) : null,
                'reason' => $item->status === 'not_due' ? $this->globalCloseMessage($item, $run) : null,
                'notice' => in_array($item->status, ['reopened', 'already_open'], true) && $item->error_code !== null
                    ? $this->globalCloseMessage($item, $run) : null,
                'tracked_period' => $tracked[$item->tenant_id] ?? null,
            ])->all(),
        ];
    }

    /**
     * Requeue failed tenants (and processing tenants whose claim expired) of a close or reopen run.
     * Finished tenants (closed, already_closed, not_due, reopened, already_open) are never touched;
     * every attempt rechecks the period.
     */
    public function retryGlobalCloseRun(int $runId, ?array $tenantIds = null): array
    {
        $runs = $this->closingRuns();
        $runs->assertUsable();
        $run = $runs->run($runId);
        if (! $run) {
            throw ValidationException::withMessages(['run_id' => ['Closing run ['.$runId.'] was not found.']]);
        }

        $selected = null;
        if ($tenantIds !== null && $tenantIds !== []) {
            $members = json_decode($run->tenant_ids, true, 512, JSON_THROW_ON_ERROR);
            $selected = [];
            foreach ($tenantIds as $index => $tenantId) {
                $tenantId = trim((string) $tenantId);
                $canonical = in_array($tenantId, $members, true) ? $tenantId
                    : (string) array_key_first(app(TenantReportRunner::class)->resolveTenants([$tenantId]));
                if (! in_array($canonical, $members, true)) {
                    throw ValidationException::withMessages(['tenant_ids.'.$index => ['Tenant ['.$tenantId.'] is not part of closing run ['.$runId.'].']]);
                }
                $selected[$canonical] = $canonical;
            }
            $selected = array_values($selected);
        }

        $this->dispatchGlobalCloseItems($runs->resetForRetry($runId, $selected));

        return $this->getGlobalCloseRun($runId);
    }

    /**
     * Crash recovery for unfinished runs: requeue processing tenants whose claim expired and
     * pending tenants whose job never ran within one lease. Safe to schedule repeatedly.
     *
     * @return list<array{run_id: int, dispatched: int}>
     */
    public function recoverGlobalCloseRuns(): array
    {
        $runs = $this->closingRuns();
        $runs->assertUsable();
        $recovered = [];
        foreach ($runs->unfinishedRunIds() as $runId) {
            $runs->refreshRun($runId);
            $ids = $runs->recoverableItemIds($runId, $this->globalCloseLeaseSeconds());
            $this->dispatchGlobalCloseItems($ids);
            $recovered[] = ['run_id' => $runId, 'dispatched' => count($ids)];
        }

        return $recovered;
    }

    /**
     * Queue-worker entry point for one tenant of a run (CloseTenantMonthJob). Claims the item and its
     * tenant/period lease, closes inside the tenant context (always restored), and records closed,
     * already_closed, not_due or failed. Duplicate or late jobs return claimed = false.
     */
    public function processGlobalCloseTenant(int $itemId): array
    {
        $runs = $this->closingRuns();
        $item = $runs->item($itemId);
        $run = $item ? $runs->run((int) $item->run_id) : null;
        if (! $item || ! $run) {
            return ['item_id' => $itemId, 'claimed' => false, 'reason' => 'missing'];
        }
        [$token, $reason] = $runs->claim($item, $run, $this->globalCloseLeaseSeconds());
        if ($token === null) {
            return ['item_id' => $itemId, 'claimed' => false, 'reason' => $reason, 'status' => $runs->item($itemId)?->status];
        }

        $year = (int) $run->year;
        $month = (int) $run->month;
        $totals = [];
        try {
            $reopen = ClosingRunRepository::operation($run) === 'reopen';
            $scheduled = $run->trigger === 'scheduled';
            // Periods closed by an earlier run, or reopened by a reopen run, were closed before.
            $closedBefore = $scheduled && ($runs->closedInAnotherRun($item->tenant_id, $year, $month, (int) $run->id)
                || $runs->reopenedByRun($item->tenant_id, $year, $month));
            // An earlier attempt of this item may have committed its reopen before the worker died.
            $previousAttempt = (int) $item->attempts > 0 ? $item->started_at : null;
            $entered = false;
            try {
                $outcome = app(TenantReportRunner::class)->run([$item->tenant_id], function () use ($year, $month, $run, $item, $reopen, $scheduled, $closedBefore, $previousAttempt, &$totals, &$entered) {
                    $entered = true;

                    return $reopen
                        ? $this->reopenTenantMonthForRun($year, $month, $previousAttempt, $run, $item)
                        : $this->closeTenantMonthForRun($year, $month, $run->actor_id, $scheduled, $closedBefore, $totals);
                })[0]['report'];
            } catch (Throwable $e) {
                $outcome = $this->globalCloseFailure($e, $run, $item, $entered);
            }
            $runs->finish($itemId, $token, $outcome + $totals);
        } finally {
            $runs->releaseLease($item->tenant_id, $year, $month, $token);
            $runs->refreshRun((int) $run->id);
        }

        return ['item_id' => $itemId, 'claimed' => true, 'status' => $outcome['status']];
    }

    /**
     * Month a scheduled global close targets by default: the latest month whose closing day has
     * arrived, or the previous calendar month when closing_day is not configured.
     *
     * @return array{year: int, month: int}
     */
    public function defaultGlobalCloseMonth(): array
    {
        $day = config('accounting.closing.closing_day');
        $month = $day === null || $day === '' ? now()->startOfMonth()->subMonth() : $this->latestDueMonth();

        return ['year' => $month->year, 'month' => $month->month];
    }

    /** Runs inside the tenant context; rechecks the period on every attempt. */
    protected function closeTenantMonthForRun(int $year, int $month, ?string $actorId, bool $scheduled, bool $closedBefore, array &$totals): array
    {
        $due = $this->monthlyClosingDate($year, $month);
        $posted = $this->postedMonthTotals($year, $month);
        $totals = [
            'total_debit' => round((float) ($posted->total_debit ?? 0), 2),
            'total_credit' => round((float) ($posted->total_credit ?? 0), 2),
        ];

        $period = FiscalPeriod::query()->where('year', $year)->where('month', $month)->first();
        if ($period?->is_closed) {
            return ['status' => 'already_closed'];
        }
        if ($due !== null && now()->lt($due)) {
            return ['status' => 'not_due', 'error_code' => 'not_due', 'error_params' => json_encode(['date' => $due->toDateString()])];
        }
        // A scheduled run never recloses a period someone reopened deliberately (retained snapshots
        // or an earlier run's close show that it was closed before).
        if ($scheduled && ($closedBefore || ($period !== null
            && MonthlyBalance::query()->where('fiscal_year', $year)->where('fiscal_month', $month)->exists()))) {
            return ['status' => 'not_due', 'error_code' => 'reopened_period'];
        }

        try {
            $this->closeMonth($year, $month, $actorId);
        } catch (ClosingPeriodException $e) {
            if ($e->reason === ClosingPeriodException::ALREADY_CLOSED) {
                return ['status' => 'already_closed'];
            }
            throw $e;
        }

        return ['status' => 'closed'];
    }

    /**
     * Runs inside the tenant context for a reopen run; rechecks the period on every attempt and
     * delegates to reopenMonth(), so its locks, rules, outbox write and cache clearing apply.
     * An open period counts as reopened by this run when an earlier attempt started before the
     * period's last change and the month still has snapshots from its close (a reopen committed
     * before the worker died); otherwise it is already_open.
     */
    protected function reopenTenantMonthForRun(int $year, int $month, ?string $previousAttempt, object $run, object $item): array
    {
        $period = FiscalPeriod::query()->where('year', $year)->where('month', $month)->first();
        if ($period !== null && ! $period->is_closed) {
            $recovered = $previousAttempt !== null && $period->updated_at !== null
                && $period->updated_at->toDateTimeString() >= $previousAttempt
                && MonthlyBalance::query()->where('fiscal_year', $year)->where('fiscal_month', $month)->exists();

            return ['status' => $recovered ? 'reopened' : 'already_open'] + $this->reopenCutoffNotice($year, $month, $run, $item);
        }

        try {
            $this->reopenMonth($year, $month, $run->actor_id);
        } catch (ClosingPeriodException $e) {
            // Reopened concurrently between the check above and the lock.
            if ($e->reason !== ClosingPeriodException::NOT_CLOSED) {
                throw $e;
            }

            return ['status' => 'already_open'] + $this->reopenCutoffNotice($year, $month, $run, $item);
        }

        return ['status' => 'reopened'] + $this->reopenCutoffNotice($year, $month, $run, $item);
    }

    /**
     * cutoff_active notice when a closing cutoff (per tenant, or shared with this tenant in scope)
     * still covers part of the reopened month, so corrections there stay blocked until
     * reopenCutoff() moves it. A failure to read the cutoff never fails the reopen; it is logged.
     */
    protected function reopenCutoffNotice(int $year, int $month, object $run, object $item): array
    {
        try {
            $state = app(FiscalPeriodService::class)->lockClosingCutoff();
            $date = $state?->cutoff_date;
            if ($date === null || $date < Carbon::create($year, $month, 1)->toDateString()) {
                return [];
            }
            if (isset($state->tenant_ids) && $state->tenant_ids !== null
                && ! in_array((string) $item->tenant_id, json_decode($state->tenant_ids, true, 512, JSON_THROW_ON_ERROR), true)) {
                return [];
            }

            return ['error_code' => 'cutoff_active', 'error_params' => json_encode(['date' => $date])];
        } catch (Throwable $e) {
            AccountingErrorReporter::report($e, 'Accounting global monthly reopening could not read the closing cutoff', [
                'closing_run_id' => (int) $run->id,
                'closing_run_tenant_id' => (int) $item->id,
                'tenant_id' => $item->tenant_id,
            ]);

            return [];
        }
    }

    /** Readable failure code plus a reference; the full exception goes to the application log. */
    protected function globalCloseFailure(Throwable $e, object $run, object $item, bool $entered): array
    {
        $reopen = ClosingRunRepository::operation($run) === 'reopen';
        $reason = $e instanceof ClosingPeriodException ? $e->reason : null;
        $code = match (true) {
            ! $entered => 'tenant_unavailable',
            $reason === ClosingPeriodException::UNBALANCED => 'unbalanced_journals',
            $reopen && $reason === ClosingPeriodException::NOT_FOUND => 'period_not_found',
            $reopen && $reason === ClosingPeriodException::LATER_MONTH_CLOSED => 'later_month_closed',
            AccountingErrorReporter::isForeignKeyViolation($e) => 'account_reference_mismatch',
            AccountingErrorReporter::isDatabaseError($e) => 'database_error',
            $e instanceof ValidationException => 'configuration_error',
            default => 'unexpected_error',
        };
        $params = match ($code) {
            'unbalanced_journals' => [
                'debit' => number_format((float) ($e->details['total_debit'] ?? 0), 2),
                'credit' => number_format((float) ($e->details['total_credit'] ?? 0), 2),
            ],
            'later_month_closed' => ['later_year' => (int) ($e->details['year'] ?? 0), 'later_month' => (int) ($e->details['month'] ?? 0)],
            default => null,
        };
        $reference = AccountingErrorReporter::report($e, $reopen ? 'Accounting global monthly reopening failed' : 'Accounting global monthly closing failed', [
            'closing_run_id' => (int) $run->id,
            'closing_run_tenant_id' => (int) $item->id,
            'tenant_id' => $item->tenant_id,
            'period' => sprintf('%04d-%02d', $run->year, $run->month),
            'error_code' => $code,
        ] + ($reopen ? ['operation' => 'reopen'] : []));

        return [
            'status' => 'failed',
            'error_code' => $code,
            'error_params' => $params === null ? null : json_encode($params),
            'error_reference' => $reference,
        ];
    }

    protected function globalCloseMessage(object $item, object $run): array
    {
        $code = $item->error_code ?? ($item->status === 'failed' ? 'unexpected_error' : 'not_due');
        $params = ($item->error_params === null ? [] : json_decode($item->error_params, true)) ?: [];
        $monthName = fn (int $year, int $month) => Carbon::create($year, $month, 1)->locale(app()->getLocale())->translatedFormat('F Y');
        if (isset($params['later_year'], $params['later_month'])) {
            $params['later'] = $monthName((int) $params['later_year'], (int) $params['later_month']);
            unset($params['later_year'], $params['later_month']);
        }
        $replace = $params + [
            'period' => $monthName((int) $run->year, (int) $run->month),
            'reference' => (string) $item->error_reference,
        ];
        // Reopen runs use their own wording (accounting::closing.reopen.*), falling back to errors.*.
        $group = ClosingRunRepository::operation($run) === 'reopen' && Lang::has('accounting::closing.reopen.'.$code.'.message')
            ? 'reopen' : 'errors';

        return [
            'code' => $code,
            'message' => trans('accounting::closing.'.$group.'.'.$code.'.message', $replace),
            'action' => trans('accounting::closing.'.$group.'.'.$code.'.action', $replace),
            'reference' => $item->error_reference,
        ];
    }

    /** Dispatch after the surrounding transaction (if any) commits, so workers see the tracking rows. */
    protected function dispatchGlobalCloseItems(array $itemIds): void
    {
        foreach ($itemIds as $itemId) {
            CloseTenantMonthJob::dispatch((int) $itemId)
                ->onConnection(config('accounting.closing.global.queue_connection'))
                ->onQueue(config('accounting.closing.global.queue'))
                ->afterCommit();
        }
    }

    protected function globalCloseLeaseSeconds(): int
    {
        return max(60, (int) config('accounting.closing.global.lease_seconds', 900));
    }

    protected function closingRuns(): ClosingRunRepository
    {
        return app(ClosingRunRepository::class);
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
