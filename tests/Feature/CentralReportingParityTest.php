<?php

namespace ESolution\LaravelAccounting\Tests\Feature;

use ESolution\LaravelAccounting\Services\AccountCategoryTreeService;
use ESolution\LaravelAccounting\Services\ConsolidatedReportService;
use ESolution\LaravelAccounting\Services\GeneralLedgerService;
use ESolution\LaravelAccounting\Services\ProfitLossSummaryService;
use ESolution\LaravelAccounting\Services\ReportingSyncService;
use ESolution\LaravelAccounting\Services\ReportService;
use ESolution\LaravelAccounting\Tests\Feature\Concerns\CentralReportingFixture;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class CentralReportingParityTest extends TestCase
{
    use CentralReportingFixture;

    public function test_every_report_matches_live_results_without_touching_tenant_databases(): void
    {
        $this->prepareReporting();
        $this->seedScenario();
        $this->backfill();
        $ids = ['tenant-a', 'beta', 'tenant-b'];

        $live = array_map(fn (callable $report) => $report(), $this->reports($ids));
        // Sanity: the fixture exercises current, stale and missing months and real amounts.
        $this->assertSame([['tenant_id' => 'tenant-a', 'status' => 'stale'], ['tenant_id' => 'tenant-b', 'status' => 'missing']],
            $live['balanceSheetSummary June']['summary']['snapshots']);
        $this->assertSame('current', $live['balanceSheetSummary April']['summary']['status']);
        $this->assertNotEquals(0.0, $live['consolidated profitLossRange']['report']['net_income']);
        $this->assertSame(['tenant-a', 'tenant-b'], array_column($live['accountBreakdown']['tenants'], 'tenant_id'), 'per-tenant rows');
        $this->assertNotEquals(0.0, $live['accountBreakdown']['totals']['total_debit']);
        $balances = fn (array $nodes) => collect($nodes)->flatMap(fn ($node) => collect($node['accounts'])->pluck('balance'))->filter()->count();
        $this->assertGreaterThan(0, $balances($live['categoryTree balances July']['data'][0]['children'][0]['children']));

        $this->useCentral();
        $this->disconnectTenants();
        DB::setDefaultConnection('rp_caller');
        $this->tenancy->initializations = [];

        foreach ($this->reports($ids) as $name => $report) {
            $this->assertSame($live[$name], $report(), $name.' differs between live and central');
        }
        $this->assertSame([], $this->tenancy->initializations, 'central reports must not initialize tenants');
        $this->assertFalse($this->tenancy->initialized);
        $this->assertSame('rp_caller', DB::getDefaultConnection());
    }

    public function test_http_tenant_list_reports_return_identical_payloads_from_central(): void
    {
        $this->prepareReporting();
        $this->seedScenario();
        $this->backfill();
        $query = '?year=2026&month=5&tenant_ids[]=tenant-a&tenant_ids[]=beta';

        $live = [$this->getJson('/api/accounting/reports/balance-sheet'.$query)->assertOk()->json(),
            $this->getJson('/api/accounting/reports/profit-loss'.$query)->assertOk()->json()];
        $this->useCentral();
        $this->disconnectTenants();
        $this->tenancy->initializations = [];

        $this->assertSame($live, [$this->getJson('/api/accounting/reports/balance-sheet'.$query)->assertOk()->json(),
            $this->getJson('/api/accounting/reports/profit-loss'.$query)->assertOk()->json()]);
        $this->assertSame([], $this->tenancy->initializations);
    }

    public function test_central_reports_read_the_master_tables_directly_and_copies_keep_central_ids(): void
    {
        $this->prepareReporting();
        $this->seedScenario();
        $this->backfill();
        $master = DB::connection('rp_master');

        // No chart copies exist; copied rows reference the existing central master ids.
        foreach (['charts', 'categories', 'accounts', 'services'] as $table) {
            $this->assertFalse($master->getSchemaBuilder()->hasTable('acc_reporting_'.$table), $table);
        }
        $this->assertFalse($master->getSchemaBuilder()->hasColumn('acc_reporting_sources', 'chart_key'));
        $accountIds = $master->table('acc_accounts')->pluck('id')->all();
        foreach (['journal_entry_details', 'monthly_balances'] as $table) {
            $referenced = $master->table('acc_reporting_'.$table)->distinct()->pluck('account_id')->all();
            $this->assertNotEmpty($referenced);
            $this->assertSame([], array_values(array_diff($referenced, $accountIds)), $table.' must reference central account ids');
        }

        // Master edits need no sync: central and live read the same master rows immediately.
        $master->table('acc_accounts')->where('code', '1001')->update(['name' => 'Main cash']);
        $live = app(ConsolidatedReportService::class)->balanceSheet(2026, 4, ['tenant-a', 'tenant-b']);
        $this->useCentral();
        $this->disconnectTenants();
        $central = app(ConsolidatedReportService::class)->balanceSheet(2026, 4, ['tenant-a', 'tenant-b']);
        $this->assertSame($live, $central);
        $asset = $central['report']['data']['asset'][0]['children'][0];
        $this->assertSame('Main cash', $asset['children'][0]['accounts'][0]['name']);
        $this->assertContains('1102', collect($asset['accounts'])->pluck('code')->all(), 'an account used by one tenant only is consolidated');
    }

    public function test_unsupported_configurations_skip_the_migration_and_are_rejected_by_sync_and_reads(): void
    {
        // Tenant-local master data: each tenant database holds its own chart.
        $this->prepareReporting(false);
        $this->asTenant('tenant-a', fn () => $this->journal('2026-04-15', ['1001' => 100, '3001' => -100]));
        $this->assertSame([], $this->logRows('rp_a'), 'the outbox records nothing for an unsupported setup');
        $message = 'Central reporting is supported only with shared master data';

        // The unified migration folder must not break unsupported installs: the central run is a no-op.
        $this->migrateCentral();
        $this->assertFalse(DB::connection('rp_master')->getSchemaBuilder()->hasTable('acc_reporting_sources'));
        $sync = app(ReportingSyncService::class);
        foreach ([fn () => $sync->backfill(['tenant-a']), fn () => $sync->sync(), fn () => $sync->reconcile(),
            fn () => $sync->prune(), fn () => $sync->status()] as $operation) {
            $this->assertThrowsMessage($operation, $message);
        }
        $this->assertSame(1, Artisan::call('accounting:reporting-sync'));
        $this->assertStringContainsString($message, Artisan::output());
        $this->tenancy->initializations = [];

        $this->useCentral();
        $this->assertThrowsMessage(fn () => app(ReportService::class)->balanceSheet(2026, 4, ['tenant-a']), $message);
        $this->assertSame([], $this->tenancy->initializations, 'rejected before any tenant or central read');

        // Live tenant-local reporting is unaffected.
        $this->useLive();
        $this->assertSame('tenant-a', app(ReportService::class)->balanceSheet(2026, 4, ['tenant-a'])[0]['tenant_id']);

        // Shared mode whose master connection lacks the master tables: still skipped, then rejected.
        config()->set('accounting.master_data.use_shared_database', true);
        $this->migrateCentral();
        $this->assertFalse(DB::connection('rp_master')->getSchemaBuilder()->hasTable('acc_reporting_sources'));
        $this->assertThrowsMessage(fn () => $sync->sync(), 'Central reporting requires the master table [acc_account_categories] on connection [rp_master]');

        // Supported configuration whose central tables were never created (the migration was recorded
        // while unsupported): rejected with instructions instead of an SQL error.
        $this->createMasterData('rp_master', true);
        $this->assertThrowsMessage(fn () => $sync->status(), 'Central reporting tables are missing on connection [rp_master]');
        $this->migrateCentral();
        $this->assertSame([], $sync->status());
    }

    public function test_requests_are_rejected_unless_every_source_is_ready(): void
    {
        $this->prepareReporting(true);
        $this->seedScenario();
        $this->backfill();
        $this->useCentral();
        $service = app(ConsolidatedReportService::class);

        // Registered and fresh: accepted.
        $this->assertSame(['tenant-a', 'tenant-b'], $service->balanceSheet(2026, 5, ['tenant-a', 'tenant-b'])['tenant_ids']);

        // An unregistered tenant rejects the entire request; nothing partial is returned.
        $this->assertRejected(fn () => $service->balanceSheet(2026, 5, ['tenant-a', 'tenant-c']), 'Tenant [tenant-c] central reporting is not ready: not registered');
        // Category retrieval and account breakdown enforce the same readiness; no live fallback.
        $this->tenancy->initializations = [];
        $this->assertRejected(fn () => $service->categoryTree(['tenant-a', 'tenant-c'], 2026, 5, true), 'Tenant [tenant-c] central reporting is not ready');
        $this->assertRejected(fn () => app(AccountCategoryTreeService::class)->index(['tenant_ids' => ['tenant-a', 'tenant-c']]), 'Tenant [tenant-c] central reporting is not ready');
        $this->assertRejected(fn () => app(GeneralLedgerService::class)->getAccountBreakdown('1001', '2026-04-01', '2026-05-31', ['tenant-c']), 'Tenant [tenant-c] central reporting is not ready');
        $this->assertSame([], $this->tenancy->initializations);

        // Freshness limit.
        $this->travel(901)->seconds();
        $this->assertRejected(fn () => $service->profitLossSummary(2026, 5, ['tenant-a', 'tenant-b']), 'stale: synced through');
        config()->set('accounting.reporting.max_lag_seconds', null);
        $service->profitLossSummary(2026, 5, ['tenant-a', 'tenant-b']);
        config()->set('accounting.reporting.max_lag_seconds', 900);
        app(ReportingSyncService::class)->sync();
        $service->profitLossSummary(2026, 5, ['tenant-a', 'tenant-b']);

        // Failed sync.
        DB::connection('rp_b')->getSchemaBuilder()->rename('acc_reporting_change_log', 'acc_reporting_change_log_moved');
        $this->assertSame('failed', app(ReportingSyncService::class)->sync(['tenant-b'])[0]['result']);
        $this->assertRejected(fn () => $service->balanceSheetSummary(2026, 5, ['tenant-a', 'tenant-b']), 'Tenant [tenant-b] central reporting is not ready: sync failed');
        DB::connection('rp_b')->getSchemaBuilder()->rename('acc_reporting_change_log_moved', 'acc_reporting_change_log');
        $this->assertSame('deferred', app(ReportingSyncService::class)->sync(['tenant-b'])[0]['result'], 'failed tenants back off');
        $this->assertSame('ok', app(ReportingSyncService::class)->sync(['tenant-b'], null, true)[0]['result']);
        $service->balanceSheetSummary(2026, 5, ['tenant-a', 'tenant-b']);

        // Drift detected without repair.
        DB::connection('rp_a')->table('acc_journal_entry_details')->limit(1)->update(['debit' => 999]);
        $this->assertSame('drifted', app(ReportingSyncService::class)->reconcile(['tenant-a'], false)[0]['status']);
        $this->assertRejected(fn () => $service->profitLossRange(self::RANGE, ['tenant-a', 'tenant-b']), 'drift detected');

        // Live mode is unaffected by central state.
        $this->useLive();
        $service->profitLossRange(self::RANGE, ['tenant-a', 'tenant-b']);
    }

    public function test_reconcile_repairs_direct_sql_changes_and_restores_parity(): void
    {
        $this->prepareReporting();
        $this->seedScenario();
        $this->backfill();

        // Unlogged mutations: direct SQL edits, deletes and inserts in journals and snapshots.
        $this->asTenant('tenant-a', function () {
            $detail = DB::table('acc_journal_entry_details')->orderBy('id')->first();
            DB::table('acc_journal_entry_details')->where('id', $detail->id)->update(['debit' => DB::raw('debit + 1'), 'credit' => DB::raw('credit')]);
            DB::table('acc_journal_entry_details')->where('journal_entry_id', $detail->journal_entry_id)->where('id', '!=', $detail->id)
                ->limit(1)->update(['credit' => DB::raw('credit + 1')]);
            $victim = DB::table('acc_journal_entries')->where('trx_date', 'like', '2026-05-20%')->value('id');
            DB::table('acc_journal_entry_details')->where('journal_entry_id', $victim)->delete();
            DB::table('acc_journal_entries')->where('id', $victim)->delete();
            DB::table('acc_monthly_balances')->where('fiscal_month', 4)->limit(1)->update(['ending_balance' => 12345]);
            DB::table('acc_journal_entries')->where('trx_date', 'like', '2026-04-15%')->update(['trx_date' => '2026-07-15']);
        });
        $this->assertSame('ok', app(ReportingSyncService::class)->sync()[0]['result']);

        $detected = app(ReportingSyncService::class)->reconcile(['tenant-a'], false)[0];
        $this->assertSame('drifted', $detected['status']);
        foreach (['2026-04', '2026-05', '2026-07'] as $month) {
            $this->assertContains($month, $detected['found']['journal_months']);
        }
        $this->assertSame(['2026-04'], $detected['found']['snapshot_months']);

        $repaired = app(ReportingSyncService::class)->reconcile(['tenant-a'])[0];
        $this->assertSame('ok', $repaired['status']);
        $this->assertSame([], $repaired['remaining']);
        $this->assertSame('ok', $this->source('tenant-a')->reconcile_status);
        $this->assertCentralMatchesSource('tenant-a');

        $live = app(ConsolidatedReportService::class)->balanceSheetSummaryByMonth(self::RANGE, ['tenant-a', 'tenant-b']);
        $this->useCentral();
        $this->assertSame($live, app(ConsolidatedReportService::class)->balanceSheetSummaryByMonth(self::RANGE, ['tenant-a', 'tenant-b']));
    }

    public function test_invalid_read_source_and_missing_central_connection_fail_explicitly(): void
    {
        $this->prepareReporting(true);
        config()->set('accounting.reporting.read_source', 'replica');
        try {
            app(ReportService::class)->balanceSheet(2026, 5, ['tenant-a']);
            $this->fail('An unknown read source must fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('accounting.reporting.read_source must be live or central.', $e->getMessage());
        }

        $this->useCentral();
        config()->set('accounting.master_data.connection', null);
        $this->expectExceptionMessage('Central reporting requires accounting.master_data.connection');
        app(ReportService::class)->balanceSheet(2026, 5, ['tenant-a']);
    }

    private function assertThrowsMessage(callable $operation, string $message): void
    {
        try {
            $operation();
            $this->fail('Expected a rejection: '.$message);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    private function assertRejected(callable $report, string $message): void
    {
        try {
            $report();
            $this->fail('Expected the central request to be rejected: '.$message);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reporting_sync', $e->errors());
            $this->assertStringContainsString($message, implode(' ', $e->errors()['reporting_sync']));
        }
    }
}
