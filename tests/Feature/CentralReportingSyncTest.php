<?php

namespace ESolution\LaravelAccounting\Tests\Feature;

use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\JournalEntry;
use ESolution\LaravelAccounting\Models\JournalEntryDetail;
use ESolution\LaravelAccounting\Models\MonthlyBalance;
use ESolution\LaravelAccounting\Repositories\CentralReportingRepository;
use ESolution\LaravelAccounting\Services\AccountOpeningBalanceService;
use ESolution\LaravelAccounting\Services\ClosingService;
use ESolution\LaravelAccounting\Services\ConsolidatedReportService;
use ESolution\LaravelAccounting\Services\JournalService;
use ESolution\LaravelAccounting\Services\ReportingSyncService;
use ESolution\LaravelAccounting\Tests\Feature\Concerns\CentralReportingFixture;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class CentralReportingSyncTest extends TestCase
{
    use CentralReportingFixture;

    // ------------------------------------------------------------------ outbox

    public function test_every_package_write_path_records_its_item_in_the_same_transaction(): void
    {
        $this->prepareReporting();

        $this->asTenant('tenant-a', function () {
            $journals = app(JournalService::class);
            $closing = app(ClosingService::class);
            $manual = $this->journal('2026-04-10', ['1001' => 10, '3001' => -10], [], false);
            $journals->journalOpeningBalance(['trx_date' => '2026-04-01', 'details' => [
                ['account_id' => $this->accountId('1001'), 'amount' => 5], ['account_id' => $this->accountId('3001'), 'amount' => 5],
            ]]);
            $reversal = $journals->reverse($manual->id, 'mistake');
            $closing->closeMonth(2026, 4);
            $closing->reopenMonth(2026, 4);
            $closing->closeThroughDate('2026-03-31', 'closer');

            $rows = $this->logRows('rp_a');
            $this->assertSame([
                ['month', '2026-04', 'opened'],
                ['journal', $manual->id, 'created'],
                ['journal', JournalEntry::where('source_type', 'OPENING_BALANCE')->value('id'), 'created'],
                ['month', '2026-08', 'opened'],
                ['journal', $reversal->id, 'reversed'],
                ['journal', $manual->id, 'reversed'],
                ['month', '2026-04', 'closed'],
                ['month', '2026-04', 'reopened'],
                ['cutoff', '1', 'close'],
            ], array_map(fn ($row) => [$row['aggregate_type'], $row['aggregate_key'], $row['operation']], $rows));
            $this->assertNull($rows[0]['acknowledged_at']);
        });

        // Account opening balances: create, then a query-builder edit of the posted journal.
        $this->asTenant('tenant-b', function () {
            DB::table('acc_reporting_change_log')->delete();
            $service = app(AccountOpeningBalanceService::class);
            $account = $service->createAccount([
                'category_id' => Account::where('code', '1001')->value('category_id'), 'code' => '1999', 'name' => 'Petty cash',
                'is_postable' => true, 'status' => true, 'opening_balance' => 50, 'opening_balance_date' => '2026-08-01',
            ]);
            $journalId = JournalEntry::where('source_id', $account->id)->value('id');
            $service->updateAccount($account, ['opening_balance' => 75, 'opening_balance_date' => '2026-08-02']);
            $this->assertSame([['month', '2026-08', 'opened'], ['journal', $journalId, 'created'], ['journal', $journalId, 'updated']],
                array_map(fn ($row) => [$row['aggregate_type'], $row['aggregate_key'], $row['operation']], $this->logRows('rp_b')));
        });
    }

    public function test_rolled_back_writes_leave_no_outbox_rows_and_unwrapped_manual_journals_are_atomic(): void
    {
        $this->prepareReporting();
        $this->asTenant('tenant-a', function () {
            try {
                $this->journal('2026-04-10', ['1001' => 10, '3001' => -9]);
                $this->fail('Unbalanced journals must fail.');
            } catch (ValidationException) {
            }
            try {
                DB::transaction(function () {
                    $this->journal('2026-04-10', ['1001' => 10, '3001' => -10]);
                    throw new RuntimeException('host rollback');
                });
            } catch (RuntimeException) {
            }
            $this->assertSame([], $this->logRows('rp_a'));
            $this->assertSame(0, JournalEntry::count());

            // wrapTransaction=false without a caller transaction: a failing detail insert rolls back
            // the header and the outbox row together (header/details/log commit as one unit).
            JournalEntryDetail::creating(function (JournalEntryDetail $detail) {
                if ((float) $detail->credit === 7.0) {
                    throw new RuntimeException('detail failure');
                }
            });
            try {
                $this->journal('2026-04-10', ['1001' => 7, '3001' => -7], [], false);
            } catch (RuntimeException) {
            }
            $this->assertSame(0, JournalEntry::count());
            $this->assertSame([], $this->logRows('rp_a'));
        });
    }

    public function test_disabled_change_log_records_nothing_and_needs_no_table(): void
    {
        $this->prepareReporting();
        config()->set('accounting.reporting.change_log_enabled', false);
        DB::connection('rp_a')->getSchemaBuilder()->drop('acc_reporting_change_log');

        $this->asTenant('tenant-a', function () {
            $journal = $this->journal('2026-04-10', ['1001' => 10, '3001' => -10], [], false);
            app(JournalService::class)->reverse($journal->id, 'undo');
            app(ClosingService::class)->closeMonth(2026, 4);
            $journal->delete();
        });

        $this->assertSame('failed', app(ReportingSyncService::class)->backfill(['tenant-a'])[0]['result']);
        $this->assertStringContainsString('Enable accounting.reporting.change_log_enabled', $this->source('tenant-a')->last_error);
    }

    public function test_eloquent_deletions_are_recorded_and_synced(): void
    {
        $this->prepareReporting();
        $this->seedScenario();
        $this->backfill();

        $this->asTenant('tenant-b', function () {
            DB::table('acc_reporting_change_log')->delete();
            // Host Eloquent deletion of a journal header (details cascade by foreign key):
            // recorded before and after the delete.
            $journal = JournalEntry::where('trx_date', 'like', '2026-07-01%')->firstOrFail();
            $journal->delete();
            $this->assertSame([['journal', $journal->id, 'deleted'], ['journal', $journal->id, 'deleted']],
                array_map(fn ($row) => [$row['aggregate_type'], $row['aggregate_key'], $row['operation']], $this->logRows('rp_b')));
            // Eloquent detail deletion of another journal records its header.
            $detail = JournalEntryDetail::query()->firstOrFail();
            $detail->delete();
            $this->assertSame($detail->journal_entry_id, $this->logRows('rp_b')[2]['aggregate_key']);
        });

        $this->assertSame('ok', app(ReportingSyncService::class)->sync()[1]['result']);
        $this->assertCentralMatchesSource('tenant-b');
        $this->assertSame('ok', app(ReportingSyncService::class)->reconcile(['tenant-b'])[0]['status']);
    }

    // ------------------------------------------------------------------ sync protocol

    public function test_backfill_then_incremental_sync_copies_exact_state_and_acknowledges_after_commit(): void
    {
        $this->prepareReporting();
        $this->seedScenario();
        $results = $this->backfill();
        $this->assertSame('ok', $results[0]['reconcile']);
        foreach (['tenant-a', 'tenant-b'] as $tenant) {
            $this->assertCentralMatchesSource($tenant);
            $source = $this->source($tenant);
            $this->assertSame(['complete', 'ok', 'ok', '2026-08-20 12:00:00'],
                [$source->backfill_status, $source->sync_status, $source->reconcile_status, $source->synced_through]);
        }
        $this->assertSame('2026-05-31', $this->source('tenant-a')->cutoff_date);
        $this->assertSame([], array_filter($this->logRows('rp_a'), fn ($row) => $row['acknowledged_at'] === null));

        // Changes after backfill are invisible centrally until the next sync.
        $this->travel(60)->seconds();
        $journal = $this->asTenant('tenant-a', fn () => $this->journal('2026-08-02', ['1001' => 9, '4001' => -9]));
        $this->assertSame([], DB::connection('rp_master')->table('acc_reporting_journal_entries')->where('id', $journal->id)->get()->all());

        $sync = app(ReportingSyncService::class)->sync();
        $this->assertSame(['ok', 1, true, '2026-08-20 12:01:00'], [$sync[0]['result'], $sync[0]['applied'], $sync[0]['drained'], $sync[0]['synced_through']]);
        $this->assertSame(['ok', 0], [$sync[1]['result'], $sync[1]['applied']]);
        $this->assertCentralMatchesSource('tenant-a');

        // Idempotent: nothing pending, a repeated run changes nothing.
        $this->assertSame(0, app(ReportingSyncService::class)->sync(['alpha'])[0]['applied']);
        $this->assertCentralMatchesSource('tenant-a');
    }

    public function test_reclosing_replaces_the_month_snapshot_set_and_reopening_keeps_it_stale(): void
    {
        // Shared master: tenant snapshot rows have no account foreign key, so an orphan row can exist.
        $this->prepareReporting();
        $this->seedScenario();
        $this->backfill();
        $central = fn () => DB::connection('rp_master')->table('acc_reporting_monthly_balances')
            ->where('tenant_id', 'tenant-a')->where('fiscal_month', 6)->count();
        $before = $central();
        $this->assertGreaterThan(0, $before, 'June snapshots were retained by the reopen');

        // Reclose after reopen: rows are replaced as a set (including an extra source row
        // closeMonth does not delete), so central equals the source month exactly.
        $this->asTenant('tenant-a', function () {
            MonthlyBalance::create(['fiscal_year' => 2026, 'fiscal_month' => 6, 'account_id' => 'orphan-account', 'ending_balance' => 1]);
            app(ClosingService::class)->closeMonth(2026, 6, 'closer');
        });
        app(ReportingSyncService::class)->sync();
        $this->assertSame($before + 1, $central());
        $this->assertCentralMatchesSource('tenant-a');
        $this->useCentral();
        $status = app(ConsolidatedReportService::class)->balanceSheetSummaryByTenant(2026, 6, ['tenant-a'])['summaries'][0]['status'];
        $this->assertSame('current', $status);

        $this->useLive();
        $this->asTenant('tenant-a', fn () => app(ClosingService::class)->reopenMonth(2026, 6, 'closer'));
        app(ReportingSyncService::class)->sync();
        $this->assertSame($before + 1, $central(), 'reopening keeps the snapshot rows');
        $this->useCentral();
        $this->assertSame('stale', app(ConsolidatedReportService::class)->balanceSheetSummaryByTenant(2026, 6, ['tenant-a'])['summaries'][0]['status']);
    }

    public function test_lower_outbox_ids_committing_after_a_higher_acknowledged_id_are_not_lost(): void
    {
        $this->prepareReporting();
        $this->seedScenario();
        $this->backfill();

        // Simulate a transaction that reserved id 1 but committed after id 2 was acknowledged.
        $this->asTenant('tenant-a', function () {
            DB::table('acc_reporting_change_log')->delete();
            $late = $this->journal('2026-08-03', ['1001' => 4, '4001' => -4]);
            DB::table('acc_reporting_change_log')->delete();
            DB::table('acc_reporting_change_log')->insert(['id' => 2, 'aggregate_type' => 'cutoff', 'aggregate_key' => '1', 'operation' => 'x', 'created_at' => now()]);
            app(ReportingSyncService::class)->sync(['tenant-a']);
            $this->assertNotNull(DB::table('acc_reporting_change_log')->where('id', 2)->value('acknowledged_at'));
            DB::table('acc_reporting_change_log')->insert(['id' => 1, 'aggregate_type' => 'journal', 'aggregate_key' => $late->id, 'operation' => 'created', 'created_at' => now()]);
        });
        // asTenant() restores context, the nested sync above ran inside tenant-a: run again from the caller.
        $this->assertSame(1, app(ReportingSyncService::class)->sync(['tenant-a'])[0]['applied']);
        $this->assertCentralMatchesSource('tenant-a');
    }

    public function test_failed_acknowledgement_reapplies_safely_and_marks_the_source_failed_until_retried(): void
    {
        $this->prepareReporting();
        $this->seedScenario();
        $this->backfill();
        $service = new class(app(CentralReportingRepository::class), app(\ESolution\LaravelAccounting\Support\ReportingChangeLog::class), app(\ESolution\LaravelAccounting\Support\AccountingTableResolver::class), app(\ESolution\LaravelAccounting\Support\AccountingConnectionResolver::class)) extends ReportingSyncService
        {
            public int $failures = 1;

            protected function acknowledge(array $ids): void
            {
                if ($this->failures-- > 0) {
                    throw new RuntimeException('tenant database unavailable for acknowledgement');
                }
                parent::acknowledge($ids);
            }
        };
        $journal = $this->asTenant('tenant-a', fn () => $this->journal('2026-08-04', ['1001' => 6, '4001' => -6]));

        $result = $service->sync(['tenant-a'])[0];
        $this->assertSame('failed', $result['result']);
        // Central committed before the acknowledgement was attempted; the outbox row stays pending.
        $this->assertSame(1, DB::connection('rp_master')->table('acc_reporting_journal_entries')->where('id', $journal->id)->count());
        $this->assertCount(1, array_filter($this->logRows('rp_a'), fn ($row) => $row['acknowledged_at'] === null));
        $source = $this->source('tenant-a');
        $this->assertSame(['failed', 1, '2026-08-20 12:01:00'], [$source->sync_status, (int) $source->failures, $source->next_attempt_at]);
        $this->assertSame('deferred', $service->sync(['tenant-a'])[0]['result']);

        $retry = $service->sync(['tenant-a'], null, true)[0];
        $this->assertSame(['ok', 1], [$retry['result'], $retry['applied']]);
        $this->assertSame([], array_filter($this->logRows('rp_a'), fn ($row) => $row['acknowledged_at'] === null));
        $this->assertSame(['ok', 0, null], [$this->source('tenant-a')->sync_status, (int) $this->source('tenant-a')->failures, $this->source('tenant-a')->next_attempt_at]);
        $this->assertCentralMatchesSource('tenant-a');
    }

    public function test_central_failure_leaves_outbox_rows_unacknowledged_and_backoff_grows(): void
    {
        $this->prepareReporting();
        $this->seedScenario();
        $this->backfill();
        $this->asTenant('tenant-a', fn () => $this->journal('2026-08-05', ['1001' => 3, '4001' => -3]));
        $this->app->instance(CentralReportingRepository::class, new class(app(\ESolution\LaravelAccounting\Support\AccountingConnectionResolver::class), app(\ESolution\LaravelAccounting\Support\AccountingTableResolver::class)) extends CentralReportingRepository
        {
            public function replaceJournals(string $tenantId, array $journalIds, array $headers, array $detailsByJournal): void
            {
                parent::replaceJournals($tenantId, $journalIds, $headers, $detailsByJournal);
                throw new RuntimeException('central write failed mid-batch');
            }
        });
        $this->app->forgetInstance(ReportingSyncService::class);
        $centralBefore = $this->centralRows('tenant-a', 'journal_entries');

        $this->assertSame('failed', app(ReportingSyncService::class)->sync(['tenant-a'])[0]['result']);
        $this->assertSame($centralBefore, $this->centralRows('tenant-a', 'journal_entries'), 'the central batch rolled back');
        $this->assertCount(1, array_filter($this->logRows('rp_a'), fn ($row) => $row['acknowledged_at'] === null));
        $this->assertSame('2026-08-20 12:01:00', $this->source('tenant-a')->next_attempt_at);

        $this->assertSame('failed', app(ReportingSyncService::class)->sync(['tenant-a'], null, true)[0]['result']);
        $this->assertSame(['2', '2026-08-20 12:02:00'], [(string) $this->source('tenant-a')->failures, $this->source('tenant-a')->next_attempt_at]);
        $this->assertStringContainsString('central write failed mid-batch', $this->source('tenant-a')->last_error);
    }

    public function test_exclusive_leases_block_concurrent_workers_and_fence_lost_leases(): void
    {
        $this->prepareReporting();
        $this->seedScenario();
        $this->backfill();
        $this->asTenant('tenant-a', fn () => $this->journal('2026-08-06', ['1001' => 2, '4001' => -2]));
        $central = app(CentralReportingRepository::class);

        // Another worker holds an unexpired lease: skipped without touching anything.
        $this->assertTrue($central->acquireLock('tenant-a', 'other-worker'));
        $this->assertSame('locked', app(ReportingSyncService::class)->sync(['tenant-a'])[0]['result']);
        $this->assertCount(1, array_filter($this->logRows('rp_a'), fn ($row) => $row['acknowledged_at'] === null));
        $this->assertSame('ok', $this->source('tenant-a')->sync_status);

        // An expired lease is taken over.
        $this->travel(601)->seconds();
        $this->assertSame('ok', app(ReportingSyncService::class)->sync(['tenant-a'])[0]['result']);
        $this->assertNull($this->source('tenant-a')->lock_owner);

        // A worker whose lease is taken mid-run cannot commit its batch (fencing).
        $this->asTenant('tenant-a', fn () => $this->journal('2026-08-07', ['1001' => 1, '4001' => -1]));
        $thief = new class(app(CentralReportingRepository::class), app(\ESolution\LaravelAccounting\Support\ReportingChangeLog::class), app(\ESolution\LaravelAccounting\Support\AccountingTableResolver::class), app(\ESolution\LaravelAccounting\Support\AccountingConnectionResolver::class)) extends ReportingSyncService
        {
            protected function applyChanges(string $tenantId, string $token, Collection $rows): void
            {
                DB::connection('rp_master')->table('acc_reporting_sources')->where('tenant_id', $tenantId)->update(['lock_owner' => 'thief']);
                parent::applyChanges($tenantId, $token, $rows);
            }
        };
        $before = $this->centralRows('tenant-a', 'journal_entries');
        $result = $thief->sync(['tenant-a'])[0];
        $this->assertSame('failed', $result['result']);
        $this->assertStringContainsString('lease for tenant [tenant-a] was lost', $result['error']);
        $this->assertSame($before, $this->centralRows('tenant-a', 'journal_entries'));
        $this->assertCount(1, array_filter($this->logRows('rp_a'), fn ($row) => $row['acknowledged_at'] === null));
        $this->assertSame('thief', $this->source('tenant-a')->lock_owner, 'the new owner keeps its lease');
    }

    public function test_backfill_does_not_lose_changes_made_while_it_copies(): void
    {
        $this->prepareReporting();
        $this->seedScenario();
        // Treat seed-time outbox rows as already processed, so the direct SQL edit below is truly unlogged.
        foreach (['rp_a', 'rp_b'] as $connection) {
            DB::connection($connection)->table('acc_reporting_change_log')->update(['acknowledged_at' => now()]);
        }
        $service = new class(app(CentralReportingRepository::class), app(\ESolution\LaravelAccounting\Support\ReportingChangeLog::class), app(\ESolution\LaravelAccounting\Support\AccountingTableResolver::class), app(\ESolution\LaravelAccounting\Support\AccountingConnectionResolver::class)) extends ReportingSyncService
        {
            protected function afterBackfillCopy(string $tenantId): void
            {
                // Runs inside the tenant context between the bulk copy and the drain.
                app(\ESolution\LaravelAccounting\Services\JournalService::class)->journalManual(['trx_date' => '2026-07-08', 'details' => [
                    ['account_code' => '1001', 'type' => 'D', 'amount' => 11], ['account_code' => '4001', 'type' => 'K', 'amount' => 11],
                ]]);
                JournalEntry::where('trx_date', 'like', '2026-07-01%')->first()?->delete();
                // Unlogged direct SQL is caught by the reconcile that ends every backfill.
                DB::table('acc_journal_entries')->where('trx_date', 'like', '2026-05-03%')->update(['description' => 'edited by SQL']);
            }
        };

        foreach ($service->backfill(['tenant-a', 'tenant-b']) as $result) {
            $this->assertSame(['ok', 'ok'], [$result['result'], $result['reconcile']]);
        }
        $this->assertCentralMatchesSource('tenant-a');
        $this->assertCentralMatchesSource('tenant-b');
        $details = json_decode($this->source('tenant-b')->reconcile_details, true);
        $this->assertSame(['2026-05'], $details['found']['journal_months'] ?? null, json_encode($details));
        $this->assertSame([], $details['remaining']);
    }

    public function test_batches_limit_runtime_and_only_a_full_drain_advances_freshness(): void
    {
        $this->prepareReporting();
        $this->seedScenario();
        $this->backfill();
        config()->set('accounting.reporting.batch_size', 2);
        $this->travel(30)->seconds();
        $this->asTenant('tenant-a', function () {
            foreach ([1, 2, 3, 4, 5] as $day) {
                $this->journal('2026-08-1'.$day, ['1001' => $day, '4001' => -$day]);
            }
        });

        $partial = app(ReportingSyncService::class)->sync(['tenant-a'], 1)[0];
        $this->assertSame(['ok', 2, false, null], [$partial['result'], $partial['applied'], $partial['drained'], $partial['synced_through']]);
        $this->assertSame('2026-08-20 12:00:00', $this->source('tenant-a')->synced_through);

        $full = app(ReportingSyncService::class)->sync(['tenant-a'])[0];
        $this->assertSame([3, true, '2026-08-20 12:00:30'], [$full['applied'], $full['drained'], $full['synced_through']]);
        $this->assertCentralMatchesSource('tenant-a');
    }

    public function test_unified_migration_folder_keeps_central_tables_on_the_master_connection(): void
    {
        $this->prepareReporting();
        $this->seedScenario();
        $this->backfill();
        $cutoffs = '2026_11_04_000017_create_shared_closing_cutoffs_tables.php';
        $master = DB::connection('rp_master')->getSchemaBuilder();
        $before = DB::connection('rp_master')->table('acc_reporting_sources')->orderBy('tenant_id')->get()->map(fn ($row) => (array) $row)->all();

        // A tenant run of every package migration (tenancy makes the tenant connection the default):
        // tenant tables are created there, central tables are neither created there nor recreated centrally.
        config()->set('database.connections.rp_d', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $files = collect(glob(dirname(__DIR__, 2).'/database/migrations/accounting/*.php'))->map(fn ($path) => basename($path))->sort()->values();
        foreach ($files as $file) {
            $this->runMigration($file, 'up', 'rp_d');
        }
        $tenant = DB::connection('rp_d')->getSchemaBuilder();
        foreach (['acc_journal_entries', 'acc_closing_cutoffs', 'acc_reporting_change_log'] as $table) {
            $this->assertTrue($tenant->hasTable($table), $table.' belongs to the tenant');
        }
        foreach (['acc_reporting_sources', 'acc_reporting_journal_entries', 'acc_shared_closing_cutoffs', 'acc_shared_closing_cutoff_events'] as $table) {
            $this->assertFalse($tenant->hasTable($table), $table.' must not be created by a tenant run');
        }
        $this->assertSame($before, DB::connection('rp_master')->table('acc_reporting_sources')->orderBy('tenant_id')->get()->map(fn ($row) => (array) $row)->all());

        // Tenant rollback of the central migrations leaves the master tables and their data.
        foreach ([self::CENTRAL_MIGRATION, $cutoffs] as $file) {
            $this->runMigration($file, 'down', 'rp_d');
        }
        $this->assertTrue($master->hasTable('acc_reporting_sources'));
        $this->assertSame($before, DB::connection('rp_master')->table('acc_reporting_sources')->orderBy('tenant_id')->get()->map(fn ($row) => (array) $row)->all());

        // Central runs create shared cutoff tables once (inert while cutoff_mode is per_tenant) and
        // never recreate existing tables or duplicate their seed row.
        foreach ([1, 2] as $run) {
            $this->runMigration($cutoffs, 'up', 'rp_master');
            $this->runMigration(self::CENTRAL_MIGRATION, 'up', 'rp_master');
        }
        $this->assertSame([1], DB::connection('rp_master')->table('acc_shared_closing_cutoffs')->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame($before, DB::connection('rp_master')->table('acc_reporting_sources')->orderBy('tenant_id')->get()->map(fn ($row) => (array) $row)->all());

        // Without a configured master connection both central migrations are no-ops on any run.
        config()->set('accounting.master_data.connection', null);
        foreach (['rp_caller', 'rp_d'] as $connection) {
            $this->runMigration($cutoffs, 'up', $connection);
            $this->runMigration(self::CENTRAL_MIGRATION, 'up', $connection);
            $this->assertFalse(DB::connection($connection)->getSchemaBuilder()->hasTable('acc_shared_closing_cutoffs'));
            $this->assertFalse(DB::connection($connection)->getSchemaBuilder()->hasTable('acc_reporting_sources'));
        }
        config()->set('accounting.master_data.connection', 'rp_master');

        // The central rollback is the only one that drops them.
        $this->runMigration(self::CENTRAL_MIGRATION, 'down', 'rp_master');
        $this->runMigration($cutoffs, 'down', 'rp_master');
        foreach (['acc_reporting_sources', 'acc_reporting_monthly_balances', 'acc_shared_closing_cutoffs'] as $table) {
            $this->assertFalse($master->hasTable($table), $table);
        }
        $this->assertTrue($master->hasTable('acc_accounts'), 'master data is untouched');
    }

    public function test_prune_removes_only_old_acknowledged_rows(): void
    {
        $this->prepareReporting();
        $this->seedScenario();
        $this->backfill();
        $this->asTenant('tenant-a', fn () => $this->journal('2026-08-09', ['1001' => 1, '4001' => -1]));
        $acknowledged = count(array_filter($this->logRows('rp_a'), fn ($row) => $row['acknowledged_at'] !== null));
        $this->assertGreaterThan(0, $acknowledged);

        $this->assertSame(0, app(ReportingSyncService::class)->prune(7, ['tenant-a'])[0]['pruned']);
        $this->travel(8)->days();
        $this->assertSame($acknowledged, app(ReportingSyncService::class)->prune(null, ['tenant-a'])[0]['pruned']);
        $this->assertSame([['journal', 'created', null]], array_map(fn ($row) => [$row['aggregate_type'], $row['operation'], $row['acknowledged_at']], $this->logRows('rp_a')));
    }

    public function test_commands_run_backfill_sync_reconcile_prune_and_status(): void
    {
        $this->prepareReporting();
        $this->seedScenario();

        $this->assertSame(2, Artisan::call('accounting:reporting-backfill'));
        $this->assertSame(0, Artisan::call('accounting:reporting-backfill', ['--tenant' => ['alpha', 'tenant-b']]));
        $this->assertSame(0, Artisan::call('accounting:reporting-sync'));
        $this->assertSame(0, Artisan::call('accounting:reporting-reconcile'));
        $this->assertSame(0, Artisan::call('accounting:reporting-prune', ['--days' => 0]));
        $this->assertSame(0, Artisan::call('accounting:reporting-status'));
        $this->assertStringContainsString('ready', Artisan::output());

        DB::connection('rp_a')->table('acc_monthly_balances')->limit(1)->update(['ending_balance' => 1]);
        $this->assertSame(1, Artisan::call('accounting:reporting-reconcile', ['--detect-only' => true, '--tenant' => ['tenant-a']]));
        $this->assertSame(1, Artisan::call('accounting:reporting-status', ['--tenant' => ['tenant-a']]));
        $this->assertStringContainsString('drift detected', Artisan::output());

        $status = app(ReportingSyncService::class)->status(['tenant-b', 'gamma']);
        $this->assertSame([true, false], array_column($status, 'ready'));
        $this->assertSame(['not registered; run accounting:reporting-backfill'], $status[1]['reasons']);
    }
}
