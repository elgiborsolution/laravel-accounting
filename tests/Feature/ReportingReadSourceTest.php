<?php

namespace ESolution\LaravelAccounting\Tests\Feature;

use ESolution\LaravelAccounting\Services\ConsolidatedReportService;
use ESolution\LaravelAccounting\Services\ReportService;
use ESolution\LaravelAccounting\Support\TenantReportRunner;
use ESolution\LaravelAccounting\Tests\Feature\Concerns\CentralReportingFixture;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * accounting.reporting.read_source selection for every report entry point that accepts a tenant
 * list: 'live' (the default, also for missing or older configs) runs the original per-tenant
 * queries; 'central' reads only central copies and master tables and never falls back.
 */
class ReportingReadSourceTest extends TestCase
{
    use CentralReportingFixture;

    private const IDS = ['tenant-a', 'beta', 'tenant-b'];

    private const CENTRAL_TABLES = ['sources', 'journal_entries', 'journal_entry_details', 'fiscal_periods', 'monthly_balances'];

    public function test_missing_empty_or_older_published_config_uses_live_tenant_queries(): void
    {
        $this->prepareReporting();
        $this->seedScenario();
        // Not backfilled: any central read would be rejected as "not registered".
        $expected = $this->runLive();
        $package = require dirname(__DIR__, 2).'/config/accounting.php';
        $current = config('accounting');
        // An accounting.php published before central reporting existed.
        $olderPublished = Arr::except($current, ['reporting']);

        $variants = [
            // mergeConfigFrom() adds the package's reporting block to an older published file.
            'older published file, merged' => fn () => config()->set('accounting', array_merge($package, $olderPublished)),
            // config:cache skips merging, so the block is absent entirely.
            'reporting block missing' => fn () => config()->set('accounting', $olderPublished),
            // A published reporting block from before read_source (merging is shallow, so it wins as a whole).
            'read_source key missing' => fn () => config()->set('accounting', ['reporting' => Arr::except($current['reporting'], ['read_source'])] + $current),
            'null' => fn () => config()->set('accounting.reporting.read_source', null),
            'empty env value' => fn () => config()->set('accounting.reporting.read_source', ''),
            'whitespace' => fn () => config()->set('accounting.reporting.read_source', '  '),
            'upper case' => fn () => config()->set('accounting.reporting.read_source', ' LIVE '),
        ];

        foreach ($variants as $name => $configure) {
            config()->set('accounting', $current);
            $configure();
            $this->assertSame('live', app(TenantReportRunner::class)->readSource(), $name);
            $this->assertSame($expected, $this->runLive(), $name.' must keep the live results');
        }

        // HTTP tenant-list endpoints follow the same default.
        config()->set('accounting', $olderPublished);
        $this->tenancy->initializations = [];
        $this->getJson('/api/accounting/reports/balance-sheet?year=2026&month=5&tenant_ids[]=tenant-a&tenant_ids[]=beta')
            ->assertOk()->assertJsonPath('data.0.tenant_id', 'tenant-a')->assertJsonPath('data.1.tenant_id', 'tenant-b');
        $this->assertSame(['tenant-a', 'tenant-b'], $this->tenancy->initializations);
    }

    public function test_live_mode_needs_no_central_tables_outbox_or_sync_state(): void
    {
        $this->prepareReporting();
        $this->seedScenario();
        $expected = $this->runLive();

        // An install that never ran the central reporting or outbox migrations.
        config()->set('accounting.reporting.change_log_enabled', false);
        $this->runMigration(self::CENTRAL_MIGRATION, 'down', 'rp_master');
        foreach (['rp_a', 'rp_b', 'rp_c'] as $connection) {
            DB::connection($connection)->getSchemaBuilder()->drop('acc_reporting_change_log');
        }
        foreach (self::CENTRAL_TABLES as $table) {
            $this->assertFalse(DB::connection('rp_master')->getSchemaBuilder()->hasTable('acc_reporting_'.$table));
        }
        DB::connection('rp_master')->enableQueryLog();

        $this->assertSame($expected, $this->runLive());
        $this->assertSame([], array_filter(
            array_column(DB::connection('rp_master')->getQueryLog(), 'query'),
            fn (string $sql) => str_contains($sql, 'acc_reporting_')
        ), 'live reports never query central reporting tables');

        // Calls without a tenant list keep using the active connection in both modes.
        DB::setDefaultConnection('rp_a');
        $withoutTenants = app(ReportService::class)->balanceSheet(2026, 5);
        $this->useCentral();
        $this->assertSame($withoutTenants, app(ReportService::class)->balanceSheet(2026, 5));
        DB::setDefaultConnection('rp_caller');
    }

    public function test_central_mode_answers_every_entry_point_from_central_copies_only(): void
    {
        $this->prepareReporting();
        $this->seedScenario();
        $this->backfill();
        $expected = $this->runLive();

        config()->set('accounting.reporting.read_source', ' Central ');
        $this->assertSame('central', app(TenantReportRunner::class)->readSource());
        $this->disconnectTenants();
        $this->tenancy->initializations = [];

        foreach ($this->reports(self::IDS) as $name => $report) {
            $this->assertSame($expected[$name], $report(), $name);
        }
        $this->assertSame([], $this->tenancy->initializations, 'central reports never initialize tenants');
        $this->assertFalse($this->tenancy->initialized);
        $this->assertSame('rp_caller', DB::getDefaultConnection());
    }

    public function test_central_mode_rejects_every_entry_point_when_sync_is_not_ready(): void
    {
        $this->prepareReporting();
        $this->seedScenario();
        $this->backfill(['tenant-a']);
        $this->useCentral();
        $this->tenancy->initializations = [];

        foreach ($this->reports(self::IDS) as $name => $report) {
            try {
                $report();
                $this->fail($name.' must be rejected instead of falling back to live');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('Tenant [tenant-b] central reporting is not ready: not registered',
                    implode(' ', $e->errors()['reporting_sync'] ?? []), $name);
            }
        }
        $this->assertSame([], $this->tenancy->initializations, 'no tenant database is used as a fallback');
    }

    public function test_central_mode_rejects_every_entry_point_without_central_setup(): void
    {
        $this->prepareReporting();
        $this->seedScenario();
        $this->runMigration(self::CENTRAL_MIGRATION, 'down', 'rp_master');
        $this->useCentral();
        $this->tenancy->initializations = [];

        foreach ($this->reports(self::IDS) as $name => $report) {
            $this->assertRuntimeRejection($report, 'Central reporting tables are missing on connection [rp_master]', $name);
        }

        // Unsupported layout: tenant-local master data.
        config()->set('accounting.master_data.use_shared_database', false);
        $this->assertRuntimeRejection(fn () => app(ConsolidatedReportService::class)->balanceSheet(2026, 5, self::IDS),
            'Central reporting is supported only with shared master data', 'unsupported layout');
        $this->assertSame([], $this->tenancy->initializations);
    }

    public function test_unknown_read_sources_are_rejected_before_any_tenant_is_used(): void
    {
        $this->prepareReporting();
        $this->tenancy->initializations = [];

        foreach (['replica', 'tenant', true, 1, ['central']] as $value) {
            config()->set('accounting.reporting.read_source', $value);
            $this->assertRuntimeRejection(fn () => app(ReportService::class)->profitLoss(2026, 5, ['tenant-a']),
                'accounting.reporting.read_source must be live or central.', var_export($value, true));
        }
        $this->assertSame([], $this->tenancy->initializations);
    }

    /** Every tenant-list entry point in live mode; tenancy is initialized per tenant and restored. */
    private function runLive(): array
    {
        $this->tenancy->initializations = [];
        $results = array_map(fn (callable $report) => $report(), $this->reports(self::IDS));
        $this->assertContains('tenant-a', $this->tenancy->initializations, 'live reports switch into tenants');
        $this->assertContains('tenant-b', $this->tenancy->initializations, 'live reports switch into tenants');
        $this->assertFalse($this->tenancy->initialized, 'tenancy is restored after live reports');
        $this->assertSame('rp_caller', DB::getDefaultConnection());

        return $results;
    }

    private function assertRuntimeRejection(callable $report, string $message, string $name): void
    {
        try {
            $report();
            $this->fail($name.' must be rejected: '.$message);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage(), $name);
        }
    }
}
