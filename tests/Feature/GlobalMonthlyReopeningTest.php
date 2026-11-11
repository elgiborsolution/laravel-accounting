<?php

namespace ESolution\LaravelAccounting\Tests\Feature;

use ESolution\LaravelAccounting\Jobs\CloseTenantMonthJob;
use ESolution\LaravelAccounting\Models\FiscalPeriod;
use ESolution\LaravelAccounting\Models\MonthlyBalance;
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

class GlobalMonthlyReopeningTest extends TestCase
{
    use CentralReportingFixture;

    private const RUNS = '2026_11_09_000020_create_acc_closing_runs_tables.php';

    private const OPERATION = '2026_11_11_000021_add_operation_to_acc_closing_runs_table.php';

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
    public function test_reopens_every_tenant_by_default_keeping_snapshots_history_and_audit(bool $shared): void
    {
        $this->prepare($shared);
        $closing = app(ClosingService::class);
        $closeRun = $closing->closeMonthGlobally(2026, 7);
        $snapshots = $this->snapshotCounts();
        // tenant-a's own cutoff still covers part of July.
        $closing->closeThroughDate('2026-07-15', 'closer', null, ['tenant-a']);

        $run = $closing->reopenMonthGlobally(2026, 7, null, '  Correct July postings ', 'auditor');

        $this->assertSame('reopen', $run['operation']);
        $this->assertSame('completed', $run['status']);
        $this->assertSame('Correct July postings', $run['reason']);
        $this->assertSame('auditor', $run['actor_id']);
        $this->assertSame(['total' => 3, 'pending' => 0, 'processing' => 0, 'reopened' => 3, 'already_open' => 0, 'failed' => 0], $run['counts']);
        foreach ($run['tenants'] as $tenant) {
            $this->assertSame(['reopened', 1, null], [$tenant['status'], $tenant['attempts'], $tenant['error']]);
            $this->assertSame(['state' => 'open', 'run_id' => $run['id'], 'operation' => 'reopen', 'status' => 'reopened'],
                array_intersect_key($tenant['tracked_period'], array_flip(['state', 'run_id', 'operation', 'status'])));
            $this->assertFalse($this->periodClosed($tenant['tenant_id'], 7));
        }
        $notice = $this->tenant($run, 'tenant-a')['notice'];
        $this->assertSame('cutoff_active', $notice['code']);
        $this->assertSame('July 2026 was reopened, but the closing cutoff through 2026-07-15 still blocks corrections dated on or before it.', $notice['message']);
        $this->assertNull($notice['reference']);
        $this->assertNull($this->tenant($run, 'tenant-b')['notice']);
        $this->assertSame($snapshots, $this->snapshotCounts(), 'reopening retains snapshots');

        $row = DB::connection('rp_master')->table('acc_closing_runs')->where('id', $run['id'])->first();
        $this->assertSame(['reopen', 'Correct July postings', 'auditor', 3, 0, 0], [$row->operation, $row->reason, $row->actor_id, (int) $row->reopened_count, (int) $row->already_open_count, (int) $row->closed_count]);
        if ($shared) {
            $this->assertContains(['aggregate_type' => 'month', 'aggregate_key' => '2026-07', 'operation' => 'reopened', 'acknowledged_at' => null], $this->logRows('rp_a'));
        }

        // The close run's history is unchanged; tracked_period shows the later reopen separately.
        $history = $closing->getGlobalCloseRun($closeRun['id']);
        $this->assertSame('close', $history['operation']);
        $this->assertSame(3, $history['counts']['closed']);
        $this->assertArrayNotHasKey('reopened', $history['counts']);
        foreach ($history['tenants'] as $tenant) {
            $this->assertSame('closed', $tenant['status']);
            $this->assertSame(['open', $run['id']], [$tenant['tracked_period']['state'], $tenant['tracked_period']['run_id']]);
        }
        $this->assertSame(0, DB::connection('rp_master')->table('acc_closing_run_locks')->whereNotNull('token')->count());
        $this->assertContextRestored();
    }

    public function test_later_closed_months_and_missing_periods_fail_readably_and_retry_reopens_only_failed_tenants(): void
    {
        $this->prepare();
        $this->captureLogs();
        $closing = app(ClosingService::class);
        $closing->closeMonthGlobally(2026, 7);
        $closing->closeMonthGlobally(2026, 8, ['tenant-a', 'tenant-b']);

        $run = $closing->reopenMonthGlobally(2026, 7, [], 'Correct July');

        $this->assertSame('partially_failed', $run['status']);
        $this->assertSame([1, 2], [$run['counts']['reopened'], $run['counts']['failed']]);
        $this->assertSame('reopened', $this->tenant($run, 'tenant-c')['status']);
        foreach (['tenant-a', 'tenant-b'] as $tenantId) {
            $error = $this->tenant($run, $tenantId)['error'];
            $this->assertSame('later_month_closed', $error['code']);
            $this->assertSame('July 2026 cannot be reopened because August 2026 is still closed.', $error['message']);
            $this->assertStringContainsString($error['reference'], $error['action']);
            $this->assertStringNotContainsString('Exception', $error['message'].$error['action']);
            $log = $this->loggedReference($error['reference']);
            $this->assertSame([$tenantId, 'reopen', 'later_month_closed'], [$log->context['tenant_id'], $log->context['operation'], $log->context['error_code']]);
            $this->assertTrue($this->periodClosed($tenantId, 7));
        }
        $this->assertNotContains(['aggregate_type' => 'month', 'aggregate_key' => '2026-07', 'operation' => 'reopened', 'acknowledged_at' => null], $this->logRows('rp_a'));
        app()->setLocale('id');
        $this->assertSame('Juli 2026 tidak dapat dibuka kembali karena Agustus 2026 masih ditutup.',
            $this->tenant($closing->getGlobalCloseRun($run['id']), 'tenant-a')['error']['message']);
        app()->setLocale('en');

        // Latest first: reopen August, then retry July's failed tenants only.
        $this->assertSame(2, $closing->reopenMonthGlobally(2026, 8, ['tenant-a', 'tenant-b'], 'Correct August')['counts']['reopened']);
        $reopenedC = $this->tenant($run, 'tenant-c');
        $this->travel(5)->minutes();
        $retried = $closing->retryGlobalCloseRun($run['id']);
        $this->assertSame('completed', $retried['status']);
        $this->assertSame([2, 2], [$this->tenant($retried, 'tenant-a')['attempts'], $this->tenant($retried, 'tenant-b')['attempts']]);
        $this->assertSame('reopened', $this->tenant($retried, 'tenant-a')['status']);
        $this->assertSame($reopenedC, $this->tenant($retried, 'tenant-c'));
        $this->assertFalse($this->periodClosed('tenant-a', 7));

        // A period that never existed fails readably; nothing else changes.
        $missing = $closing->reopenMonthGlobally(2026, 3, ['tenant-a'], 'Correct March');
        $this->assertSame('failed', $missing['status']);
        $this->assertSame('period_not_found', $missing['tenants'][0]['error']['code']);
        $this->assertSame('The March 2026 fiscal period does not exist for this company, so there is nothing to reopen.', $missing['tenants'][0]['error']['message']);

        $runs = DB::connection('rp_master')->table('acc_closing_runs')->count();
        $this->assertInvalid(fn () => $closing->reopenMonthGlobally(2026, 7, null, '   '));
        $this->assertInvalid(fn () => $closing->reopenMonthGlobally(2026, 7, ['tenant-a', 'missing'], 'x'));
        $this->assertInvalid(fn () => $closing->reopenMonthGlobally(2026, 13, null, 'x'));
        $this->assertInvalid(fn () => $closing->reopenMonthGlobally(2026, 7, null, str_repeat('x', 1001)));
        $this->assertSame($runs, DB::connection('rp_master')->table('acc_closing_runs')->count());
    }

    public function test_unavailable_tenants_fail_without_stopping_others_and_the_callers_context_is_restored(): void
    {
        $this->prepare();
        $this->captureLogs();
        $closing = app(ClosingService::class);
        $closing->closeMonthGlobally(2026, 7);
        Queue::fake();
        $run = $closing->reopenMonthGlobally(2026, 7, null, 'Correct July');
        Queue::assertPushed(CloseTenantMonthJob::class, 3);
        $this->assertSame('pending', $run['status']);
        ReportingTenant::whereKey('tenant-b')->delete();
        $this->tenancy->initialize(ReportingTenant::findOrFail('tenant-c')); // The caller's own tenant context.

        foreach (['tenant-a', 'tenant-b', 'tenant-c'] as $tenantId) {
            $closing->processGlobalCloseTenant($this->itemId($run['id'], $tenantId));
            $this->assertSame('tenant-c', $this->tenancy->tenant->getKey());
            $this->assertSame('rp_c', DB::getDefaultConnection());
            foreach (['rp_master', 'rp_a', 'rp_c'] as $connection) {
                $this->assertSame(0, DB::connection($connection)->transactionLevel());
            }
        }

        $run = $closing->getGlobalCloseRun($run['id']);
        $this->assertSame('partially_failed', $run['status']);
        $error = $this->tenant($run, 'tenant-b')['error'];
        $this->assertSame('tenant_unavailable', $error['code']);
        $this->assertSame('This company\'s accounting data could not be opened to reopen July 2026.', $error['message']);
        $this->assertNotNull($this->loggedReference($error['reference']));
        $this->assertSame(['reopened', 'reopened'], [$this->tenant($run, 'tenant-a')['status'], $this->tenant($run, 'tenant-c')['status']]);
    }

    public function test_duplicate_jobs_never_reopen_twice_and_close_and_reopen_share_the_tenant_period_lease(): void
    {
        $this->prepare();
        $closing = app(ClosingService::class);
        $repository = app(ClosingRunRepository::class);
        $closing->closeMonthGlobally(2026, 7, ['tenant-a']);
        Queue::fake();
        $reopen = $closing->reopenMonthGlobally(2026, 7, ['tenant-a'], 'Correct July');
        $reopenItem = $this->itemId($reopen['id'], 'tenant-a');
        $close = $closing->closeMonthGlobally(2026, 7, ['tenant-a']);
        $closeItem = $this->itemId($close['id'], 'tenant-a');

        // A reopen worker holds the tenant/period lease: the close job of another run must wait.
        [$token] = $repository->claim($repository->item($reopenItem), $repository->run($reopen['id']), 900);
        $this->assertSame(['item_id' => $closeItem, 'claimed' => false, 'reason' => 'busy', 'status' => 'pending'], $closing->processGlobalCloseTenant($closeItem));
        $this->assertSame(['item_id' => $reopenItem, 'claimed' => false, 'reason' => 'not_claimable', 'status' => 'processing'], $closing->processGlobalCloseTenant($reopenItem));
        $this->assertTrue($this->periodClosed('tenant-a', 7));

        // The worker died; its expired claim is taken over once and fenced afterwards.
        $this->travel(16)->minutes();
        $this->assertSame(['item_id' => $reopenItem, 'claimed' => true, 'status' => 'reopened'], $closing->processGlobalCloseTenant($reopenItem));
        $this->assertSame(['item_id' => $reopenItem, 'claimed' => false, 'reason' => 'not_claimable', 'status' => 'reopened'], $closing->processGlobalCloseTenant($reopenItem));
        $this->assertFalse($repository->finish($reopenItem, $token, ['status' => 'failed']));
        $this->assertSame(2, $closing->getGlobalCloseRun($reopen['id'])['tenants'][0]['attempts']);
        $this->assertCount(1, array_filter($this->logRows('rp_a'), fn ($row) => $row['aggregate_key'] === '2026-07' && $row['operation'] === 'reopened'));

        // The waiting manual close runs afterwards (a deliberate decision).
        $this->assertSame('closed', $closing->processGlobalCloseTenant($closeItem)['status']);
        $this->assertTrue($this->periodClosed('tenant-a', 7));
    }

    public function test_crash_recovery_recognizes_committed_reopens_without_repeating_them(): void
    {
        $this->prepare();
        $closing = app(ClosingService::class);
        $repository = app(ClosingRunRepository::class);
        $closing->closeMonthGlobally(2026, 7);
        // tenant-c was reopened by hand before the run.
        $this->travel(-1)->minutes();
        $this->asTenant('tenant-c', fn () => $closing->reopenMonth(2026, 7));
        $this->travel(1)->minutes();
        Queue::fake();
        $run = $closing->reopenMonthGlobally(2026, 7, null, 'Correct July');
        $items = ['a' => $this->itemId($run['id'], 'tenant-a'), 'b' => $this->itemId($run['id'], 'tenant-b'), 'c' => $this->itemId($run['id'], 'tenant-c')];

        // tenant-a's worker committed the reopen and died before recording it; tenant-b's died first.
        [$tokenA] = $repository->claim($repository->item($items['a']), $repository->run($run['id']), 900);
        $this->asTenant('tenant-a', fn () => $closing->reopenMonth(2026, 7));
        $repository->claim($repository->item($items['b']), $repository->run($run['id']), 900);
        $this->assertSame([['run_id' => $run['id'], 'dispatched' => 0]], $closing->recoverGlobalCloseRuns());
        $this->assertSame('processing', $closing->getGlobalCloseRun($run['id'])['status']);

        $this->travel(16)->minutes();
        $this->assertTrue($this->tenant($closing->getGlobalCloseRun($run['id']), 'tenant-a')['stale']);
        Queue::fake();
        $this->assertSame([['run_id' => $run['id'], 'dispatched' => 3]], $closing->recoverGlobalCloseRuns());
        Queue::assertPushed(CloseTenantMonthJob::class, 3);

        $this->assertSame('reopened', $closing->processGlobalCloseTenant($items['a'])['status']);
        $this->assertSame('reopened', $closing->processGlobalCloseTenant($items['b'])['status']);
        $this->assertSame('already_open', $closing->processGlobalCloseTenant($items['c'])['status']);
        $this->assertFalse($repository->finish($items['a'], $tokenA, ['status' => 'failed']));
        $this->assertCount(1, array_filter($this->logRows('rp_a'), fn ($row) => $row['aggregate_key'] === '2026-07' && $row['operation'] === 'reopened'));

        $run = $closing->getGlobalCloseRun($run['id']);
        $this->assertSame('completed', $run['status']);
        $this->assertSame([2, 1], [$run['counts']['reopened'], $run['counts']['already_open']]);
        $row = DB::connection('rp_master')->table('acc_closing_runs')->where('id', $run['id'])->first();
        $this->assertSame([2, 1], [(int) $row->reopened_count, (int) $row->already_open_count]);
        $this->assertSame([], $closing->recoverGlobalCloseRuns());
    }

    public function test_scheduled_closing_never_recloses_globally_reopened_periods(): void
    {
        $this->prepare();
        $closing = app(ClosingService::class);
        $closing->closeMonthGlobally(2026, 7, ['tenant-a', 'tenant-c']);
        // tenant-b was closed directly (no run); after the global reopen its snapshots are removed,
        // so only the reopen run's tracking shows that it was closed before.
        $this->asTenant('tenant-b', fn () => $closing->closeMonth(2026, 7));
        $closing->reopenMonthGlobally(2026, 7, ['tenant-a', 'tenant-b'], 'Correct July');
        $this->asTenant('tenant-b', fn () => MonthlyBalance::query()->delete());

        $scheduled = $closing->closeMonthGlobally(2026, 7, null, null, true);

        $this->assertSame('completed', $scheduled['status']);
        foreach (['tenant-a', 'tenant-b'] as $tenantId) {
            $tenant = $this->tenant($scheduled, $tenantId);
            $this->assertSame(['not_due', 'reopened_period'], [$tenant['status'], $tenant['reason']['code']]);
            $this->assertFalse($this->periodClosed($tenantId, 7));
        }
        $this->assertSame('already_closed', $this->tenant($scheduled, 'tenant-c')['status']);
        $this->assertSame($scheduled, $closing->closeMonthGlobally(2026, 7, null, null, true));

        $manual = $closing->closeMonthGlobally(2026, 7, ['tenant-a', 'tenant-b']);
        $this->assertSame(2, $manual['counts']['closed']);
    }

    public function test_shared_cutoff_is_never_moved_and_reports_a_notice_until_reopen_cutoff_is_called(): void
    {
        $this->prepare();
        config()->set('accounting.closing.cutoff_mode', 'shared');
        $this->runMigration('2026_11_04_000017_create_shared_closing_cutoffs_tables.php', 'up', 'rp_master');
        $closing = app(ClosingService::class);
        $closing->closeThroughDate('2026-07-31', 'closer', 'initial', ['tenant-a', 'tenant-b']);
        $closing->closeMonthGlobally(2026, 7, ['tenant-c']);
        $state = fn () => (array) DB::connection('rp_master')->table('acc_shared_closing_cutoffs')->first(['cutoff_date', 'tenant_ids']);
        $before = $state();

        $run = $closing->reopenMonthGlobally(2026, 7, null, 'Correct July', 'auditor');

        $this->assertSame('completed', $run['status']);
        $this->assertSame(3, $run['counts']['reopened']);
        $this->assertSame($before, $state());
        $this->assertSame(1, DB::connection('rp_master')->table('acc_shared_closing_cutoff_events')->count());
        foreach (['tenant-a', 'tenant-b'] as $tenantId) {
            $this->assertSame('cutoff_active', $this->tenant($run, $tenantId)['notice']['code']);
            $this->assertStringContainsString('through 2026-07-31', $this->tenant($run, $tenantId)['notice']['message']);
        }
        $this->assertNull($this->tenant($run, 'tenant-c')['notice'], 'tenant-c is outside the shared scope');
        $this->assertContextRestored();

        // Moving the cutoff stays explicit; the global reopen made it possible.
        $event = $closing->reopenCutoff('2026-06-30', 'Correct July', 'auditor');
        $this->assertSame(['reopen', '2026-06-30'], [$event['action'], $event['cutoff_date']]);
    }

    public function test_reopened_months_reach_central_reporting_with_retained_snapshots(): void
    {
        $this->prepare();
        $closing = app(ClosingService::class);
        $closing->closeMonthGlobally(2026, 7, ['tenant-a', 'tenant-b']);
        $this->backfill(['tenant-a', 'tenant-b']);
        $central = fn () => DB::connection('rp_master')->table('acc_reporting_monthly_balances')->where('fiscal_month', 7)->count();
        $balances = $central();
        $this->assertGreaterThan(0, $balances);

        $closing->reopenMonthGlobally(2026, 7, ['tenant-a', 'tenant-b'], 'Correct July');
        $this->assertSame(['aggregate_type' => 'month', 'aggregate_key' => '2026-07', 'operation' => 'reopened', 'acknowledged_at' => null], last($this->logRows('rp_a')));

        app(ReportingSyncService::class)->sync(['tenant-a', 'tenant-b']);

        foreach (['tenant-a', 'tenant-b'] as $tenantId) {
            $this->assertCentralMatchesSource($tenantId);
            $july = collect($this->centralRows($tenantId, 'fiscal_periods'))->firstWhere('month', 7);
            $this->assertFalse((bool) $july['is_closed']);
        }
        $this->assertSame($balances, $central(), 'central keeps the retained snapshots');
        $this->assertNotNull(last($this->logRows('rp_a'))['acknowledged_at']);
    }

    public function test_command_reopens_reports_retries_and_validates(): void
    {
        $this->prepare();
        $closing = app(ClosingService::class);
        $closing->closeMonthGlobally(2026, 7);
        $this->asTenant('tenant-a', fn () => $closing->closeMonth(2026, 8));

        $this->artisan('accounting:closing-close-month', ['--reopen' => true, '--year' => 2026, '--month' => 7, '--reason' => 'Correct July', '--actor' => 'auditor'])
            ->expectsOutputToContain('Reopening run #2 (manual) for 2026-07: partially_failed')
            ->expectsOutputToContain('Reason: Correct July')
            ->expectsOutputToContain('August 2026 is still closed')
            ->assertExitCode(1);
        $this->artisan('accounting:closing-close-month', ['--status' => 2])
            ->expectsOutputToContain('Reopening run #2 (manual) for 2026-07: partially_failed')->assertExitCode(1);

        $this->asTenant('tenant-a', fn () => $closing->reopenMonth(2026, 8));
        $this->artisan('accounting:closing-close-month', ['--retry' => 2])
            ->expectsOutputToContain('Reopening run #2 (manual) for 2026-07: completed')->assertExitCode(0);
        $this->assertSame('auditor', $closing->getGlobalCloseRun(2)['actor_id']);
        $this->artisan('accounting:closing-close-month', ['--status' => 1])
            ->expectsOutputToContain('Closing run #1 (manual) for 2026-07: completed')->assertExitCode(0);
        $this->artisan('accounting:closing-close-month', ['--recover' => true])->assertExitCode(0);

        $this->artisan('accounting:closing-close-month', ['--reopen' => true, '--year' => 2026, '--month' => 7])
            ->expectsOutputToContain('The reason field is required.')->assertExitCode(1);
        $this->artisan('accounting:closing-close-month', ['--reopen' => true, '--month' => 7, '--reason' => 'x'])
            ->expectsOutputToContain('Pass --year and --month to reopen.')->assertExitCode(1);
        $this->artisan('accounting:closing-close-month', ['--reopen' => true, '--scheduled' => true, '--year' => 2026, '--month' => 7, '--reason' => 'x'])
            ->expectsOutputToContain('Reopening is never scheduled')->assertExitCode(1);
        $this->assertSame(2, DB::connection('rp_master')->table('acc_closing_runs')->count());
    }

    public function test_operation_columns_belong_to_the_master_connection_and_closing_works_without_them(): void
    {
        $this->prepareReporting();
        $this->runMigration(self::RUNS, 'up', 'rp_master');
        $this->asTenant('tenant-a', fn () => $this->journal('2026-07-10', ['1001' => 100, '4001' => -100]));
        $closing = app(ClosingService::class);
        $close = $closing->closeMonthGlobally(2026, 7, ['tenant-a']);
        $this->assertSame(['close', null, 'completed'], [$close['operation'], $close['reason'], $close['status']]);
        try {
            $closing->reopenMonthGlobally(2026, 7, null, 'Correct July');
            $this->fail('Missing operation columns must be reported.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Global monthly reopening columns are missing on connection [rp_master]', $e->getMessage());
        }

        $this->runMigration(self::OPERATION, 'up', 'rp_a');
        $this->assertFalse(Schema::connection('rp_master')->hasColumn('acc_closing_runs', 'operation'));
        $this->assertFalse(Schema::connection('rp_a')->hasTable('acc_closing_runs'));
        $this->runMigration(self::OPERATION, 'up', 'rp_master');
        $this->assertSame('close', DB::connection('rp_master')->table('acc_closing_runs')->where('id', $close['id'])->value('operation'));
        $this->assertSame('reopened', $closing->reopenMonthGlobally(2026, 7, ['tenant-a'], 'Correct July')['tenants'][0]['status']);

        $this->runMigration(self::OPERATION, 'down', 'rp_a');
        $this->assertTrue(Schema::connection('rp_master')->hasColumn('acc_closing_runs', 'operation'));
        $this->runMigration(self::OPERATION, 'down', 'rp_master');
        $this->assertFalse(Schema::connection('rp_master')->hasColumn('acc_closing_runs', 'reason'));
    }

    // ------------------------------------------------------------------ helpers

    /** Three tenants with July activity in tenant-a and tenant-b; "now" is 2026-08-20. */
    private function prepare(bool $shared = true): void
    {
        $this->prepareReporting($shared);
        $this->runMigration(self::RUNS, 'up', 'rp_master');
        $this->runMigration(self::OPERATION, 'up', 'rp_master');
        $this->asTenant('tenant-a', fn () => $this->journal('2026-07-10', ['1001' => 100, '4001' => -100]));
        $this->asTenant('tenant-b', fn () => $this->journal('2026-07-12', ['1001' => 40, '3001' => -40]));
    }

    private function snapshotCounts(): array
    {
        return collect(['tenant-a', 'tenant-b', 'tenant-c'])->mapWithKeys(fn ($tenantId) => [
            $tenantId => $this->asTenant($tenantId, fn () => MonthlyBalance::where('fiscal_month', 7)->count()),
        ])->all();
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
