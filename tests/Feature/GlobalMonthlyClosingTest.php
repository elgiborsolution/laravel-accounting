<?php

namespace ESolution\LaravelAccounting\Tests\Feature;

use ESolution\LaravelAccounting\Jobs\CloseTenantMonthJob;
use ESolution\LaravelAccounting\Models\FiscalPeriod;
use ESolution\LaravelAccounting\Models\JournalEntry;
use ESolution\LaravelAccounting\Models\JournalEntryDetail;
use ESolution\LaravelAccounting\Models\MonthlyBalance;
use ESolution\LaravelAccounting\Repositories\CentralReportingRepository;
use ESolution\LaravelAccounting\Repositories\ClosingRunRepository;
use ESolution\LaravelAccounting\Services\ClosingService;
use ESolution\LaravelAccounting\Services\ReportingSyncService;
use ESolution\LaravelAccounting\Tests\Feature\Concerns\CentralReportingFixture;
use ESolution\LaravelAccounting\Tests\Feature\Concerns\ReportingTenant;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class GlobalMonthlyClosingTest extends TestCase
{
    use CentralReportingFixture;

    private const MIGRATION = '2026_11_09_000020_create_acc_closing_runs_tables.php';

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public static function masterModes(): array
    {
        return ['shared master' => [true], 'tenant-local master' => [false]];
    }

    #[DataProvider('masterModes')]
    public function test_closes_every_tenant_by_default_and_tracks_progress(bool $shared): void
    {
        $this->prepare($shared);
        $midClose = [];
        Event::listen('eloquent.updating: '.FiscalPeriod::class, function (FiscalPeriod $period) use (&$midClose) {
            if ($period->isDirty('is_closed') && $period->is_closed) {
                $midClose[$this->tenancy->tenant->getKey()] = [
                    'run' => DB::connection('rp_master')->table('acc_closing_runs')->value('status'),
                    'items' => DB::connection('rp_master')->table('acc_closing_run_tenants')->orderBy('id')->pluck('status', 'tenant_id')->all(),
                ];
            }
        });

        $run = app(ClosingService::class)->closeMonthGlobally(2026, 7, null, 'closer');

        $this->assertSame('completed', $run['status']);
        $this->assertSame('manual', $run['trigger']);
        $this->assertSame('closer', $run['actor_id']);
        $this->assertSame(['tenant-a', 'tenant-b', 'tenant-c'], $run['tenant_ids']);
        $this->assertSame(3, $run['counts']['closed']);
        $this->assertSame(0, $run['counts']['failed'] + $run['counts']['pending'] + $run['counts']['processing']);
        $this->assertNotNull($run['started_at']);
        $this->assertNotNull($run['finished_at']);
        $this->assertSame(['tenant-a' => [100.0, 100.0], 'tenant-b' => [40.0, 40.0], 'tenant-c' => [0.0, 0.0]],
            collect($run['tenants'])->mapWithKeys(fn ($t) => [$t['tenant_id'] => [$t['total_debit'], $t['total_credit']]])->all());
        foreach ($run['tenants'] as $tenant) {
            $this->assertSame('closed', $tenant['status']);
            $this->assertSame(1, $tenant['attempts']);
            $this->assertNull($tenant['error']);
            $this->assertFalse($tenant['stale']);
            $this->assertTrue($this->periodClosed($tenant['tenant_id'], 7));
        }
        // Progress is visible while each tenant closes: earlier tenants finished, later ones pending.
        $this->assertSame(['run' => 'processing', 'items' => ['tenant-a' => 'processing', 'tenant-b' => 'pending', 'tenant-c' => 'pending']], $midClose['tenant-a']);
        $this->assertSame(['tenant-a' => 'closed', 'tenant-b' => 'processing', 'tenant-c' => 'pending'], $midClose['tenant-b']['items']);
        $this->assertSame('closer', $this->asTenant('tenant-a', fn () => FiscalPeriod::where('month', 7)->value('closed_by')));
        if ($shared) {
            $this->assertContains(['aggregate_type' => 'month', 'aggregate_key' => '2026-07', 'operation' => 'closed', 'acknowledged_at' => null], $this->logRows('rp_a'));
        }
        $this->assertSame(0, DB::connection('rp_master')->table('acc_closing_run_locks')->whereNotNull('token')->count());
        $this->assertContextRestored();
    }

    public function test_explicit_tenant_lists_are_resolved_and_deduplicated_before_the_run_is_saved(): void
    {
        $this->prepare();
        $run = app(ClosingService::class)->closeMonthGlobally(2026, 7, ['alpha', 'tenant-a', ' gamma '], 7);

        $this->assertSame(['tenant-a', 'tenant-c'], $run['tenant_ids']);
        $this->assertSame(2, $run['counts']['total']);
        $this->assertSame('7', $run['actor_id']);
        $this->assertFalse($this->periodClosed('tenant-b', 7));

        try {
            app(ClosingService::class)->closeMonthGlobally(2026, 7, ['tenant-a', 'missing']);
            $this->fail('Unknown tenants must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('tenant_ids.1', $e->errors());
        }
        $this->assertSame(1, DB::connection('rp_master')->table('acc_closing_runs')->count());
        $this->assertInvalid(fn () => app(ClosingService::class)->closeMonthGlobally(2026, 13));
    }

    public function test_partial_failure_is_readable_logged_with_its_reference_and_retry_closes_only_failed_tenants(): void
    {
        $this->prepare();
        $this->captureLogs();
        $this->migrateMonthlyBalances('rp_b', withAccountForeignKey: true);

        $run = app(ClosingService::class)->closeMonthGlobally(2026, 7);

        $this->assertSame('partially_failed', $run['status']);
        $this->assertSame(['closed' => 2, 'failed' => 1], ['closed' => $run['counts']['closed'], 'failed' => $run['counts']['failed']]);
        $failed = $this->tenant($run, 'tenant-b');
        $this->assertSame('failed', $failed['status']);
        $this->assertSame([40.0, 40.0], [$failed['total_debit'], $failed['total_credit']]);
        $error = $failed['error'];
        $this->assertSame('account_reference_mismatch', $error['code']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $error['reference']);
        $this->assertStringContainsString('July 2026', $error['message']);
        $this->assertStringContainsString($error['reference'], $error['action']);
        foreach (['SQL', 'insert', 'acc_', 'FOREIGN', 'Exception'] as $technical) {
            $this->assertStringNotContainsString($technical, $error['message'].$error['action']);
        }
        $log = $this->loggedReference($error['reference']);
        $this->assertSame('tenant-b', $log->context['tenant_id']);
        $this->assertStringContainsString('FOREIGN KEY constraint failed', $log->context['exception']->getPrevious()->getMessage());
        $this->assertStringContainsString('insert into', $log->context['exception']->getPrevious()->getMessage());
        // The failed tenant rolled back completely; others stayed closed.
        $this->assertFalse($this->periodClosed('tenant-b', 7));
        $this->assertSame(0, $this->asTenant('tenant-b', fn () => MonthlyBalance::count()));
        $this->assertSame(0, DB::connection('rp_b')->transactionLevel());
        $this->assertContextRestored();

        $this->migrateMonthlyBalances('rp_b', withAccountForeignKey: false);
        $closedA = $this->tenant($run, 'tenant-a');
        $this->travel(5)->minutes();
        $retried = app(ClosingService::class)->retryGlobalCloseRun($run['id'], ['beta']);

        $this->assertSame('completed', $retried['status']);
        $this->assertSame(['status' => 'closed', 'attempts' => 2, 'error' => null],
            array_intersect_key($this->tenant($retried, 'tenant-b'), array_flip(['status', 'attempts', 'error'])));
        $this->assertSame($closedA, $this->tenant($retried, 'tenant-a'));
        $this->assertTrue($this->periodClosed('tenant-b', 7));
        $this->assertNotSame($run['finished_at'], $retried['finished_at']);
        // Nothing left to retry; a tenant outside the run is rejected.
        $this->assertSame($retried['tenants'], app(ClosingService::class)->retryGlobalCloseRun($run['id'])['tenants']);
        $this->assertInvalid(fn () => app(ClosingService::class)->retryGlobalCloseRun($run['id'], ['missing']));
    }

    public function test_unbalanced_journals_and_unavailable_tenants_fail_without_stopping_others_and_restore_context(): void
    {
        $this->prepare();
        $this->captureLogs();
        $this->asTenant('tenant-a', function () {
            JournalEntryDetail::create(['journal_entry_id' => JournalEntry::value('id'), 'account_id' => $this->accountId('5001'), 'debit' => 5, 'credit' => 0]);
        });
        Queue::fake();
        $run = app(ClosingService::class)->closeMonthGlobally(2026, 7);
        Queue::assertPushed(CloseTenantMonthJob::class, 3);
        ReportingTenant::whereKey('tenant-b')->delete();
        $this->tenancy->initialize(ReportingTenant::findOrFail('tenant-c')); // The caller's own tenant context.

        foreach (['tenant-a', 'tenant-b', 'tenant-c'] as $tenantId) {
            app(ClosingService::class)->processGlobalCloseTenant($this->itemId($run['id'], $tenantId));
            $this->assertSame('tenant-c', $this->tenancy->tenant->getKey());
            $this->assertSame('rp_c', DB::getDefaultConnection());
        }

        $run = app(ClosingService::class)->getGlobalCloseRun($run['id']);
        $this->assertSame('partially_failed', $run['status']);
        $unbalanced = $this->tenant($run, 'tenant-a')['error'];
        $this->assertSame('unbalanced_journals', $unbalanced['code']);
        $this->assertStringContainsString('debit 105.00, credit 100.00', $unbalanced['message']);
        $this->assertNotNull($this->loggedReference($unbalanced['reference']));
        $unavailable = $this->tenant($run, 'tenant-b')['error'];
        $this->assertSame('tenant_unavailable', $unavailable['code']);
        $this->assertSame([null, null], [$this->tenant($run, 'tenant-b')['total_debit'], $this->tenant($run, 'tenant-b')['total_credit']]);
        $this->assertNotNull($this->loggedReference($unavailable['reference']));
        $this->assertSame('closed', $this->tenant($run, 'tenant-c')['status']);
    }

    public function test_duplicate_jobs_and_overlapping_runs_never_close_a_tenant_period_twice(): void
    {
        $this->prepare();
        Queue::fake();
        $closing = app(ClosingService::class);
        $first = $closing->closeMonthGlobally(2026, 7, ['tenant-a']);
        $this->assertSame('pending', $first['status']);
        $this->assertSame(['pending', 0], [$first['tenants'][0]['status'], $first['tenants'][0]['attempts']]);
        $item = $this->itemId($first['id'], 'tenant-a');

        $this->assertSame(['item_id' => $item, 'claimed' => true, 'status' => 'closed'], $closing->processGlobalCloseTenant($item));
        $this->assertSame(['item_id' => $item, 'claimed' => false, 'reason' => 'not_claimable', 'status' => 'closed'], $closing->processGlobalCloseTenant($item));
        $this->assertSame(1, $closing->getGlobalCloseRun($first['id'])['tenants'][0]['attempts']);
        $snapshots = $this->asTenant('tenant-a', fn () => MonthlyBalance::where('fiscal_month', 7)->get(['id', 'closed_at'])->toArray());

        // A second run for the same tenant/period waits while another worker holds the lease.
        $second = $closing->closeMonthGlobally(2026, 7, ['tenant-a']);
        $other = $this->itemId($second['id'], 'tenant-a');
        DB::connection('rp_master')->table('acc_closing_run_locks')->where('tenant_id', 'tenant-a')
            ->update(['token' => 'another-worker', 'item_id' => 999, 'expires_at' => now()->addMinutes(10)->toDateTimeString()]);
        $this->assertSame(['item_id' => $other, 'claimed' => false, 'reason' => 'busy', 'status' => 'pending'], $closing->processGlobalCloseTenant($other));
        $this->assertSame(0, $closing->getGlobalCloseRun($second['id'])['tenants'][0]['attempts']);

        $this->travel(11)->minutes();
        $this->assertSame('already_closed', $closing->processGlobalCloseTenant($other)['status']);
        $this->assertSame('completed', $closing->getGlobalCloseRun($second['id'])['status']);
        $this->assertSame($snapshots, $this->asTenant('tenant-a', fn () => MonthlyBalance::where('fiscal_month', 7)->get(['id', 'closed_at'])->toArray()));
        $this->assertCount(1, array_filter($this->logRows('rp_a'), fn ($row) => $row['aggregate_key'] === '2026-07' && $row['operation'] === 'closed'));
    }

    public function test_crashed_workers_are_recovered_after_their_lease_and_late_writes_are_fenced(): void
    {
        $this->prepare();
        Queue::fake();
        $closing = app(ClosingService::class);
        $repository = app(ClosingRunRepository::class);
        $run = $closing->closeMonthGlobally(2026, 7, ['tenant-a', 'tenant-b']);
        $itemA = $this->itemId($run['id'], 'tenant-a');
        $itemB = $this->itemId($run['id'], 'tenant-b');

        // A worker claims tenant-a and dies before closing it.
        [$crashedToken] = $repository->claim($repository->item($itemA), $repository->run($run['id']), 900);
        $progress = $closing->getGlobalCloseRun($run['id']);
        $this->assertSame('processing', $progress['status']);
        $this->assertSame(['processing', false], [$this->tenant($progress, 'tenant-a')['status'], $this->tenant($progress, 'tenant-a')['stale']]);
        $this->assertFalse($closing->processGlobalCloseTenant($itemA)['claimed']);
        $this->assertSame([['run_id' => $run['id'], 'dispatched' => 0]], $closing->recoverGlobalCloseRuns());

        // Tenant-b's worker committed its close but crashed before recording the outcome.
        [$crashedB] = $repository->claim($repository->item($itemB), $repository->run($run['id']), 900);
        $this->asTenant('tenant-b', fn () => $closing->closeMonth(2026, 7, 'crashed-worker'));

        $this->travel(16)->minutes();
        $this->assertTrue($this->tenant($closing->getGlobalCloseRun($run['id']), 'tenant-a')['stale']);
        Queue::fake();
        $this->assertSame([['run_id' => $run['id'], 'dispatched' => 2]], $closing->recoverGlobalCloseRuns());
        Queue::assertPushed(CloseTenantMonthJob::class, 2);

        $this->assertSame('closed', $closing->processGlobalCloseTenant($itemA)['status']);
        $this->assertSame('already_closed', $closing->processGlobalCloseTenant($itemB)['status']);
        $this->assertFalse($repository->finish($itemA, $crashedToken, ['status' => 'failed']));
        $this->assertFalse($repository->finish($itemB, $crashedB, ['status' => 'failed']));

        $run = $closing->getGlobalCloseRun($run['id']);
        $this->assertSame('completed', $run['status']);
        $this->assertSame([2, 2], [$this->tenant($run, 'tenant-a')['attempts'], $this->tenant($run, 'tenant-b')['attempts']]);
        $this->assertSame([], $closing->recoverGlobalCloseRuns());
    }

    public function test_dispatch_waits_for_the_callers_commit_and_a_rolled_back_caller_is_recoverable(): void
    {
        $this->prepare();
        $closing = app(ClosingService::class);
        $inside = DB::connection('rp_caller')->transaction(fn () => $closing->closeMonthGlobally(2026, 7, ['tenant-a']));
        $this->assertSame('pending', $inside['status']);
        $this->assertSame('completed', $closing->getGlobalCloseRun($inside['id'])['status']);

        try {
            DB::connection('rp_caller')->transaction(function () use ($closing, &$rolledBack) {
                $rolledBack = $closing->closeMonthGlobally(2026, 7, ['tenant-b']);
                throw new RuntimeException('caller failed');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame('pending', $closing->getGlobalCloseRun($rolledBack['id'])['status']);
        $this->assertFalse($this->periodClosed('tenant-b', 7));

        $this->travel(16)->minutes();
        $this->assertSame([['run_id' => $rolledBack['id'], 'dispatched' => 1]], $closing->recoverGlobalCloseRuns());
        $this->assertSame('completed', $closing->getGlobalCloseRun($rolledBack['id'])['status']);
        $this->assertTrue($this->periodClosed('tenant-b', 7));
    }

    public function test_scheduled_runs_skip_deliberately_reopened_periods_and_resume_instead_of_rerunning(): void
    {
        $this->prepare();
        $closing = app(ClosingService::class);
        $this->asTenant('tenant-a', function () use ($closing) {
            $closing->closeMonth(2026, 7);
            $closing->reopenMonth(2026, 7);
        });
        // tenant-b was closed by an earlier run, reopened, and its retained snapshots were removed.
        $closing->closeMonthGlobally(2026, 7, ['tenant-b']);
        $this->asTenant('tenant-b', function () use ($closing) {
            $closing->reopenMonth(2026, 7);
            MonthlyBalance::query()->delete();
        });

        $scheduled = $closing->closeMonthGlobally(2026, 7, null, null, true);

        $this->assertSame('scheduled', $scheduled['trigger']);
        $this->assertSame('completed', $scheduled['status']);
        foreach (['tenant-a', 'tenant-b'] as $tenantId) {
            $tenant = $this->tenant($scheduled, $tenantId);
            $this->assertSame('not_due', $tenant['status']);
            $this->assertSame('reopened_period', $tenant['reason']['code']);
            $this->assertStringContainsString('was reopened', $tenant['reason']['message']);
            $this->assertFalse($this->periodClosed($tenantId, 7));
        }
        $this->assertSame('closed', $this->tenant($scheduled, 'tenant-c')['status']);

        $again = $closing->closeMonthGlobally(2026, 7, null, null, true);
        $this->assertSame($scheduled, $again);
        $this->assertSame(2, DB::connection('rp_master')->table('acc_closing_runs')->count());

        // A manual run is a deliberate decision and may close the reopened periods.
        $manual = $closing->closeMonthGlobally(2026, 7, ['tenant-a', 'tenant-b']);
        $this->assertSame(2, $manual['counts']['closed']);
    }

    public function test_months_before_their_closing_day_are_reported_as_not_due_in_the_current_locale(): void
    {
        $this->prepare();
        config()->set('accounting.closing.closing_day', 5);
        $closing = app(ClosingService::class);
        $this->assertSame(['year' => 2026, 'month' => 7], $closing->defaultGlobalCloseMonth());

        $run = $closing->closeMonthGlobally(2026, 8);
        $this->assertSame('completed', $run['status']);
        $this->assertSame(3, $run['counts']['not_due']);
        $reason = $run['tenants'][0]['reason'];
        $this->assertSame('not_due', $reason['code']);
        $this->assertSame('August 2026 cannot be closed before 2026-09-05.', $reason['message']);
        $this->assertNull($reason['reference']);
        $this->assertFalse($this->periodClosed('tenant-a', 8));

        app()->setLocale('id');
        $this->assertSame('Agustus 2026 belum dapat ditutup sebelum 2026-09-05.', $closing->getGlobalCloseRun($run['id'])['tenants'][0]['reason']['message']);
        config()->set('accounting.closing.closing_day', null);
        $this->assertSame(['year' => 2026, 'month' => 7], $closing->defaultGlobalCloseMonth());
    }

    public function test_shared_cutoff_mode_closes_months_without_publishing_or_changing_the_shared_scope(): void
    {
        $this->prepare();
        config()->set('accounting.closing.cutoff_mode', 'shared');
        $this->runMigration('2026_11_04_000017_create_shared_closing_cutoffs_tables.php', 'up', 'rp_master');
        $closing = app(ClosingService::class);
        $closing->closeThroughDate('2026-06-30', 'closer', 'initial', ['tenant-a', 'tenant-b']);
        $state = fn () => (array) DB::connection('rp_master')->table('acc_shared_closing_cutoffs')->first(['cutoff_date', 'tenant_ids']);
        $before = $state();

        $run = $closing->closeMonthGlobally(2026, 7);

        $this->assertSame('completed', $run['status']);
        $this->assertSame(3, $run['counts']['closed']);
        $this->assertSame($before, $state());
        $this->assertSame('2026-06-30', $before['cutoff_date']);
        $this->assertSame(1, DB::connection('rp_master')->table('acc_shared_closing_cutoff_events')->count());
        $this->assertTrue($this->periodClosed('tenant-c', 7));
        $this->assertContextRestored();
    }

    public function test_tracking_tables_belong_to_the_master_connection_only(): void
    {
        $this->prepareReporting();
        $this->runMigration(self::MIGRATION, 'up', 'rp_a');
        $this->assertFalse(Schema::connection('rp_a')->hasTable('acc_closing_runs'));
        try {
            app(ClosingService::class)->closeMonthGlobally(2026, 7);
            $this->fail('Missing tracking tables must be reported.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Global monthly closing tables are missing on connection [rp_master]', $e->getMessage());
        }
        $this->runMigration(self::MIGRATION, 'up', 'rp_master');
        $this->assertTrue(Schema::connection('rp_master')->hasTable('acc_closing_run_locks'));
        $this->runMigration(self::MIGRATION, 'down', 'rp_a');
        $this->assertTrue(Schema::connection('rp_master')->hasTable('acc_closing_runs'));
    }

    public function test_command_starts_reports_retries_and_schedules_runs(): void
    {
        $this->prepare();
        $this->migrateMonthlyBalances('rp_b', withAccountForeignKey: true);

        $this->artisan('accounting:closing-close-month', ['--year' => 2026, '--month' => 7])
            ->expectsOutputToContain('Closing run #1 (manual) for 2026-07: partially_failed')
            ->expectsOutputToContain('the accounts in this company\'s database do not match the chart of accounts')
            ->assertExitCode(1);
        $this->artisan('accounting:closing-close-month', ['--status' => 1])->assertExitCode(1);

        $this->migrateMonthlyBalances('rp_b', withAccountForeignKey: false);
        $this->artisan('accounting:closing-close-month', ['--retry' => 1])
            ->expectsOutputToContain('Closing run #1 (manual) for 2026-07: completed')->assertExitCode(0);
        $this->artisan('accounting:closing-close-month', ['--scheduled' => true, '--recover' => true])
            ->expectsOutputToContain('Closing run #2 (scheduled) for 2026-07: completed')->assertExitCode(0);
        $this->assertSame(3, app(ClosingService::class)->getGlobalCloseRun(2)['counts']['already_closed']);
        $this->artisan('accounting:closing-close-month', ['--recover' => true])->assertExitCode(0);
        $this->artisan('accounting:closing-close-month', ['--year' => 2026])->assertExitCode(1);
        $this->artisan('accounting:closing-close-month', ['--status' => 99])->expectsOutputToContain('Closing run [99] was not found.')->assertExitCode(1);
    }

    public function test_reporting_failures_store_and_expose_readable_text_while_the_log_keeps_the_sql(): void
    {
        $this->prepare();
        $this->captureLogs();
        $this->backfill(['tenant-a']);
        $this->asTenant('tenant-a', fn () => $this->journal('2026-08-01', ['1001' => 5, '4001' => -5]));
        Schema::connection('rp_a')->drop('acc_journal_entry_details');

        $result = app(ReportingSyncService::class)->sync(['tenant-a'])[0];

        $this->assertSame('failed', $result['result']);
        $this->assertMatchesRegularExpression('/^a database error occurred \(SQLSTATE HY000\); see the application log \(reference [0-9a-f-]{36}\)$/', $result['error']);
        $lastError = $this->source('tenant-a')->last_error;
        $this->assertStringStartsWith('sync: a database error occurred (SQLSTATE HY000)', $lastError);
        $this->assertStringNotContainsString('select', $lastError);
        preg_match('/reference ([0-9a-f-]{36})/', $lastError, $match);
        $this->assertStringContainsString('acc_journal_entry_details', $this->loggedReference($match[1])->context['exception']->getPrevious()->getMessage());

        // Rows stored before sanitization are cleaned when read.
        DB::connection('rp_master')->table('acc_reporting_sources')->where('tenant_id', 'tenant-a')->update([
            'last_error' => 'sync: Illuminate\Database\QueryException: SQLSTATE[23000]: Integrity constraint violation: 19 FOREIGN KEY constraint failed (Connection: rp_a, SQL: insert into "acc_monthly_balances" ("id") values (1))',
        ]);
        try {
            app(CentralReportingRepository::class)->assertReady(['tenant-a']);
            $this->fail('A failed source is not ready.');
        } catch (ValidationException $e) {
            $message = $e->errors()['reporting_sync'][0];
            $this->assertStringContainsString('sync failed: sync: a database error occurred (SQLSTATE 23000); see the application log', $message);
            $this->assertStringNotContainsString('insert into', $message);
        }
        $this->assertSame('sync: a database error occurred (SQLSTATE 23000); see the application log',
            app(ReportingSyncService::class)->status(['tenant-a'])[0]['last_error']);
    }

    // ------------------------------------------------------------------ helpers

    /** Three tenants with July activity in tenant-a and tenant-b; "now" is 2026-08-20. */
    private function prepare(bool $shared = true): void
    {
        $this->prepareReporting($shared);
        $this->runMigration(self::MIGRATION, 'up', 'rp_master');
        $this->asTenant('tenant-a', fn () => $this->journal('2026-07-10', ['1001' => 100, '4001' => -100]));
        $this->asTenant('tenant-b', fn () => $this->journal('2026-07-12', ['1001' => 40, '3001' => -40]));
    }

    /**
     * Recreate a tenant's snapshot table. With the account foreign key, the table matches a tenant
     * migrated while master data was tenant-local (its own, empty acc_accounts) that now reads the
     * shared chart: every snapshot insert violates the key.
     */
    private function migrateMonthlyBalances(string $connection, bool $withAccountForeignKey): void
    {
        $schema = Schema::connection($connection);
        $schema->dropIfExists('acc_monthly_balances');
        if ($withAccountForeignKey && ! $schema->hasTable('acc_accounts')) {
            $schema->create('acc_accounts', fn ($table) => $table->uuid('id')->primary());
        }
        config()->set('accounting.master_data.use_shared_database', ! $withAccountForeignKey);
        try {
            $this->runMigration('2026_01_01_000008_create_acc_monthly_balances_table.php', 'up', $connection);
        } finally {
            config()->set('accounting.master_data.use_shared_database', true);
        }
    }

    private function tenant(array $run, string $tenantId): array
    {
        return collect($run['tenants'])->firstWhere('tenant_id', $tenantId);
    }

    private function itemId(int $runId, string $tenantId): int
    {
        return (int) DB::connection('rp_master')->table('acc_closing_run_tenants')
            ->where('run_id', $runId)->where('tenant_id', $tenantId)->value('id');
    }

    private function periodClosed(string $tenantId, int $month): bool
    {
        return $this->asTenant($tenantId, fn () => (bool) FiscalPeriod::where('year', 2026)->where('month', $month)->value('is_closed'));
    }

    private function captureLogs(): void
    {
        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event;
        });
    }

    private function loggedReference(string $reference): MessageLogged
    {
        foreach ($this->logged as $event) {
            if (($event->context['reference'] ?? null) === $reference) {
                $this->assertSame('error', $event->level);

                return $event;
            }
        }
        $this->fail('No log entry carries reference '.$reference.'.');
    }

    private function assertContextRestored(): void
    {
        $this->assertFalse($this->tenancy->initialized);
        $this->assertSame('rp_caller', DB::getDefaultConnection());
        foreach (['rp_master', 'rp_a', 'rp_b', 'rp_c'] as $connection) {
            $this->assertSame(0, DB::connection($connection)->transactionLevel());
        }
    }

    private function assertInvalid(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected validation failure.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }
}
