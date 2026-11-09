<?php

namespace ESolution\LaravelAccounting\Tests\Feature\Concerns;

use ESolution\LaravelAccounting\Enums\JournalStatus;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use ESolution\LaravelAccounting\Models\FiscalPeriod;
use ESolution\LaravelAccounting\Models\JournalEntry;
use ESolution\LaravelAccounting\Models\JournalEntryDetail;
use ESolution\LaravelAccounting\Models\MonthlyBalance;
use ESolution\LaravelAccounting\Models\Service;
use ESolution\LaravelAccounting\Repositories\AccountCategoryRepository;
use ESolution\LaravelAccounting\Repositories\AccountRepository;
use ESolution\LaravelAccounting\Services\ClosingService;
use ESolution\LaravelAccounting\Services\ConsolidatedReportService;
use ESolution\LaravelAccounting\Services\ReportService;
use ESolution\LaravelAccounting\Services\JournalService;
use ESolution\LaravelAccounting\Services\ReportingSyncService;
use ESolution\LaravelAccounting\Support\TenantReportRunner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separate SQLite databases: rp_registry (tenant model), rp_master (shared master data and the
 * central reporting tables), rp_a/rp_b/rp_c tenant transaction databases and rp_caller (the
 * caller's own default connection). Data is created through the package services so the
 * reporting outbox is exercised by its real write paths. Central reporting supports only
 * shared master data; prepareReporting(false) builds a tenant-local master install for the
 * rejection tests and skips the central migrations, which refuse that configuration.
 */
trait CentralReportingFixture
{
    protected object $tenancy;

    protected const TENANTS = ['tenant-a' => ['alpha', 'rp_a'], 'tenant-b' => ['beta', 'rp_b'], 'tenant-c' => ['gamma', 'rp_c']];

    protected const RANGE = ['start_year' => 2026, 'start_month' => 4, 'end_year' => 2026, 'end_month' => 8];

    protected function prepareReporting(bool $shared = true): void
    {
        $this->travelTo(Carbon::parse('2026-08-20 12:00:00'));
        foreach (['rp_registry', 'rp_master', 'rp_a', 'rp_b', 'rp_c', 'rp_caller'] as $connection) {
            config()->set('database.connections.'.$connection, [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
            ]);
            DB::purge($connection);
        }
        Schema::connection('rp_registry')->create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('slug')->unique();
            $table->string('connection_name');
        });
        foreach (self::TENANTS as $id => [$slug, $connection]) {
            ReportingTenant::create(['id' => $id, 'slug' => $slug, 'connection_name' => $connection]);
        }

        config()->set('tenancy.tenant_model', ReportingTenant::class);
        config()->set('accounting.master_data.use_shared_database', $shared);
        config()->set('accounting.master_data.connection', 'rp_master');
        config()->set('accounting.reporting.change_log_enabled', true);
        config()->set('accounting.reporting.read_source', 'live');
        config()->set('accounting.reporting.max_lag_seconds', 900);
        config()->set('accounting.closing.cutoff_enabled', true);
        config()->set('accounting.closing.cutoff_mode', 'per_tenant');
        config()->set('accounting.fiscal.start_month', 1);

        foreach ($shared ? ['rp_master'] : ['rp_a', 'rp_b', 'rp_c'] as $connection) {
            $this->createMasterData($connection, $connection === 'rp_b');
        }
        foreach (['rp_a', 'rp_b', 'rp_c'] as $connection) {
            DB::setDefaultConnection($connection);
            foreach (['2026_01_01_000005_create_acc_journal_entries_table.php', '2026_01_01_000006_create_acc_journal_entry_details_table.php',
                '2026_01_01_000007_create_acc_fiscal_periods_table.php', '2026_01_01_000008_create_acc_monthly_balances_table.php',
                '2026_01_01_000010_add_reversal_fields_to_acc_journal_entries_table.php', '2026_11_04_000016_create_acc_closing_cutoffs_tables.php',
                '2026_11_06_000018_create_acc_reporting_change_log_table.php'] as $migration) {
                (require dirname(__DIR__, 3).'/database/migrations/accounting/'.$migration)->up();
            }
        }
        if ($shared) {
            $this->migrateCentral();
        }

        $this->tenancy = new class
        {
            public bool $initialized = false;

            public ?Model $tenant = null;

            public array $initializations = [];

            public function initialize(Model $tenant): void
            {
                $this->initializations[] = $tenant->getKey();
                $this->tenant = $tenant;
                $this->initialized = true;
                DB::setDefaultConnection($tenant->connection_name);
            }

            public function end(): void
            {
                $this->tenant = null;
                $this->initialized = false;
                DB::setDefaultConnection('rp_caller');
            }
        };
        $this->app->instance(TenantReportRunner::class, new class($this->tenancy) extends TenantReportRunner
        {
            public function __construct(private object $hostTenancy) {}

            protected function tenancy(): object
            {
                return $this->hostTenancy;
            }
        });
        // Category consolidation identifies the live tenant through the host tenancy double (as
        // CategoryRetrievalServiceTest does); central mode must never reach this override.
        $tenancy = $this->tenancy;
        $this->app->singleton(ConsolidatedReportService::class, fn ($app) => new class(
            $app->make(ReportService::class), $app->make(TenantReportRunner::class),
            $app->make(AccountCategoryRepository::class), $app->make(AccountRepository::class), $tenancy
        ) extends ConsolidatedReportService
        {
            public function __construct(ReportService $reports, TenantReportRunner $tenants, AccountCategoryRepository $categories, AccountRepository $accounts, private object $hostTenancy)
            {
                parent::__construct($reports, $tenants, $categories, $accounts);
            }

            protected function categoryTenantIdentifier(): string
            {
                if (! $this->hostTenancy->initialized) {
                    throw new \RuntimeException('categoryTenantIdentifier() needs an initialized tenant.');
                }

                return (string) $this->hostTenancy->tenant->getKey();
            }
        });
        DB::setDefaultConnection('rp_caller');
    }

    /** The central reporting migration, run the way `migrate --database=rp_master` runs it. */
    protected function migrateCentral(): void
    {
        $this->runMigration(self::CENTRAL_MIGRATION, 'up', 'rp_master');
    }

    protected const CENTRAL_MIGRATION = '2026_11_06_000019_create_acc_reporting_central_tables.php';

    /**
     * Run one package migration as the migrator does for $connection: that connection is the
     * default for the run (tenancy bootstrappers do the same per tenant).
     */
    protected function runMigration(string $file, string $method, string $connection): void
    {
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection($connection);
        try {
            (require dirname(__DIR__, 3).'/database/migrations/accounting/'.$file)->{$method}();
        } finally {
            DB::setDefaultConnection($previous);
        }
    }

    protected function createMasterData(string $connection, bool $extraAccount): void
    {
        DB::setDefaultConnection($connection);
        foreach (['2026_01_01_000001_create_acc_account_categories_table.php', '2026_01_01_000002_create_acc_accounts_table.php',
            '2026_01_01_000003_create_acc_services_table.php', '2026_06_30_000011_add_parent_id_to_acc_account_categories_table.php'] as $migration) {
            (require dirname(__DIR__, 3).'/database/migrations/accounting/'.$migration)->up();
        }
        $categories = [];
        $sequence = 0;
        foreach ([
            'ASSET' => [null, 'ASSET'], 'CURRENT_ASSET' => ['ASSET', 'ASSET'], 'CASH' => ['CURRENT_ASSET', 'ASSET'],
            'LIABILITY' => [null, 'LIABILITY'], 'CURRENT_LIABILITY' => ['LIABILITY', 'LIABILITY'], 'EQUITY' => [null, 'EQUITY'],
            'REVENUE' => [null, 'REVENUE'], 'SALES_REVENUE' => ['REVENUE', 'REVENUE'],
            'EXPENSE' => [null, 'EXPENSE'], 'OPERATING_EXPENSE' => ['EXPENSE', 'EXPENSE'],
        ] as $code => [$parent, $type]) {
            $categories[$code] = AccountCategory::on($connection)->create([
                'parent_id' => $parent === null ? null : $categories[$parent], 'type' => $type, 'sequence_no' => ++$sequence,
                'category_code' => $code, 'category_name' => $code, 'status' => true,
                'report_type' => in_array($type, ['REVENUE', 'EXPENSE'], true) ? 'PL' : 'BS',
            ])->id;
        }
        $accounts = ['1001' => 'CASH', '1101' => 'CURRENT_ASSET', '2001' => 'CURRENT_LIABILITY', '3001' => 'EQUITY',
            '4001' => 'SALES_REVENUE', '5001' => 'OPERATING_EXPENSE'];
        if ($extraAccount || $connection === 'rp_master') {
            $accounts['1102'] = 'CURRENT_ASSET';
        }
        foreach ($accounts as $code => $category) {
            Account::on($connection)->create([
                'category_id' => $categories[$category], 'code' => $code, 'name' => 'Account '.$code,
                'is_postable' => true, 'status' => true,
            ]);
        }
        Service::on($connection)->create(['service_code' => 'MONTH_END_CLOSING', 'service_name' => 'Month end', 'module_name' => 'CLOSING', 'is_active' => true]);
        DB::setDefaultConnection('rp_caller');
    }

    /** Run inside a tenant's context through the host tenancy double. */
    protected function asTenant(string $tenantId, callable $callback): mixed
    {
        return app(TenantReportRunner::class)->run([$tenantId], $callback)[0]['report'];
    }

    /** Manual journal; $lines maps account code => signed amount (positive debit, negative credit). */
    protected function journal(string $date, array $lines, array $attributes = [], bool $wrapTransaction = true): JournalEntry
    {
        $details = [];
        foreach ($lines as $code => $amount) {
            $details[] = ['account_code' => (string) $code, 'type' => $amount > 0 ? 'D' : 'K', 'amount' => abs($amount)];
        }

        return app(JournalService::class)->journalManual(['trx_date' => $date, 'details' => $details] + $attributes, $wrapTransaction);
    }

    /**
     * tenant-a: April/May closed (current), June closed then reopened with a later posting
     * (stale), cutoff through May, a closing-transfer journal and a reversal dated August.
     * tenant-b: full opening balance, April closed only, an Eloquent draft posted through
     * the service; only tenant-b uses account 1102.
     */
    protected function seedScenario(): void
    {
        $this->asTenant('tenant-a', function () {
            $closing = app(ClosingService::class);
            $this->journal('2026-04-15', ['1001' => 100, '3001' => -100]);
            $this->journal('2026-05-10', ['1101' => 70, '4001' => -70]);
            $this->journal('2026-05-20', ['5001' => 20, '1001' => -20]);
            $closing->closeMonth(2026, 4, 'closer');
            $closing->closeMonth(2026, 5, 'closer');
            $sale = $this->journal('2026-06-05', ['1001' => 30, '4001' => -30]);
            $this->journal('2026-06-28', ['4001' => 10, '3001' => -10], ['source_type' => 'MONTH_END_CLOSING']);
            $closing->closeMonth(2026, 6, 'closer');
            $closing->reopenMonth(2026, 6, 'closer');
            $this->journal('2026-06-29', ['5001' => 5, '2001' => -5]);
            $closing->closeThroughDate('2026-05-31', 'closer', 'audit');
            app(JournalService::class)->reverse($sale->id, 'customer return', 'reverser');
        });
        $this->asTenant('tenant-b', function () {
            app(JournalService::class)->journalOpeningBalance(['trx_date' => '2026-04-01', 'details' => [
                ['account_id' => $this->accountId('1001'), 'amount' => 200], ['account_id' => $this->accountId('3001'), 'amount' => 200],
            ]]);
            $this->journal('2026-04-20', ['1101' => 40, '4001' => -40]);
            $this->journal('2026-04-22', ['1102' => 15, '4001' => -15]);
            $this->journal('2026-05-03', ['5001' => 25, '1001' => -25]);
            app(ClosingService::class)->closeMonth(2026, 4, 'closer');
            $draft = JournalEntry::create(['journal_no' => 'DRAFT-0001', 'trx_date' => '2026-05-15', 'status' => JournalStatus::DRAFT, 'amount' => 8]);
            JournalEntryDetail::create(['journal_entry_id' => $draft->id, 'account_id' => $this->accountId('5001'), 'debit' => 8, 'credit' => 0]);
            JournalEntryDetail::create(['journal_entry_id' => $draft->id, 'account_id' => $this->accountId('2001'), 'debit' => 0, 'credit' => 8]);
            app(JournalService::class)->post($draft->id, 'poster');
            $this->journal('2026-07-01', ['1001' => 12, '2001' => -12]);
        });
    }

    /** Account id by code in the chart the active context resolves (the shared master chart). */
    protected function accountId(string $code): string
    {
        return (string) Account::query()->where('code', $code)->value('id');
    }

    protected function backfill(array $tenants = ['tenant-a', 'tenant-b']): array
    {
        $results = app(ReportingSyncService::class)->backfill($tenants);
        foreach ($results as $result) {
            $this->assertSame('ok', $result['result'], json_encode($result));
        }

        return $results;
    }

    protected function useCentral(): void
    {
        config()->set('accounting.reporting.read_source', 'central');
    }

    protected function useLive(): void
    {
        config()->set('accounting.reporting.read_source', 'live');
    }

    /** Exact source rows of one tenant versus the central payload copies. */
    protected function assertCentralMatchesSource(string $tenantId): void
    {
        $source = $this->asTenant($tenantId, fn () => [
            'journal_entries' => $this->sourceRows(JournalEntry::query()->toBase()),
            'journal_entry_details' => $this->sourceRows(JournalEntryDetail::query()->toBase()),
            'fiscal_periods' => $this->sourceRows(FiscalPeriod::query()->toBase()),
            'monthly_balances' => $this->sourceRows(MonthlyBalance::query()->toBase()),
            'cutoff' => DB::table('acc_closing_cutoffs')->where('id', 1)->value('cutoff_date'),
        ]);
        foreach (['journal_entries', 'journal_entry_details', 'fiscal_periods', 'monthly_balances'] as $table) {
            $this->assertSame($source[$table], $this->centralRows($tenantId, $table), $table.' differs for '.$tenantId);
        }
        $this->assertSame($source['cutoff'], DB::connection('rp_master')->table('acc_reporting_sources')->where('tenant_id', $tenantId)->value('cutoff_date'));
    }

    protected function sourceRows($query): array
    {
        return $query->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    protected function centralRows(string $tenantId, string $table): array
    {
        return DB::connection('rp_master')->table('acc_reporting_'.$table)->where('tenant_id', $tenantId)->orderBy('id')
            ->pluck('payload')->map(fn ($payload) => json_decode($payload, true))->all();
    }

    protected function logRows(string $connection): array
    {
        return DB::connection($connection)->table('acc_reporting_change_log')->orderBy('id')
            ->get(['aggregate_type', 'aggregate_key', 'operation', 'acknowledged_at'])->map(fn ($row) => (array) $row)->all();
    }

    protected function source(string $tenantId): object
    {
        return DB::connection('rp_master')->table('acc_reporting_sources')->where('tenant_id', $tenantId)->first();
    }

    /** Make tenant databases unusable: any tenant read fails loudly. */
    protected function disconnectTenants(): void
    {
        foreach (['rp_a', 'rp_b', 'rp_c'] as $connection) {
            config()->set('database.connections.'.$connection.'.database', sys_get_temp_dir().'/missing-'.$connection.'-'.uniqid().'.sqlite');
            DB::purge($connection);
        }
    }
}
