<?php

namespace ESolution\LaravelAccounting\Tests\Feature;

use ESolution\LaravelAccounting\Enums\JournalStatus;
use ESolution\LaravelAccounting\Exceptions\AccountingPeriodLockedException;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use ESolution\LaravelAccounting\Models\FiscalPeriod;
use ESolution\LaravelAccounting\Models\JournalEntry;
use ESolution\LaravelAccounting\Models\JournalEntryDetail;
use ESolution\LaravelAccounting\Models\MonthlyBalance;
use ESolution\LaravelAccounting\Models\Service;
use ESolution\LaravelAccounting\Models\ServiceAccount;
use ESolution\LaravelAccounting\Repositories\FiscalPeriodRepository;
use ESolution\LaravelAccounting\Services\AccountOpeningBalanceService;
use ESolution\LaravelAccounting\Services\ClosingService;
use ESolution\LaravelAccounting\Services\FiscalPeriodService;
use ESolution\LaravelAccounting\Services\JournalService;
use ESolution\LaravelAccounting\Support\TenantReportRunner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClosingCutoffTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public static function masterModes(): array
    {
        return ['tenant-local master' => [false], 'shared master' => [true]];
    }

    public function test_cutoff_configuration_is_published_with_legacy_safe_defaults(): void
    {
        $this->assertSame(false, config('accounting.closing.cutoff_enabled'));
        $this->assertNull(config('accounting.closing.closing_day'));
        $this->assertSame('per_tenant', config('accounting.closing.cutoff_mode'));
        $this->assertArrayNotHasKey('cutoff_date', config('accounting.closing'));
        $this->assertNull(app(ClosingService::class)->getCutoffDate());
        $this->assertInvalid(fn () => app(ClosingService::class)->closeThroughDate('2026-11-15'));
    }

    #[DataProvider('masterModes')]
    public function test_midmonth_cutoff_is_inclusive_and_does_not_create_partial_snapshots(bool $shared): void
    {
        $this->prepareDatabases($shared);
        $closing = app(ClosingService::class);
        $event = $closing->closeThroughDate('2026-11-15', 12, 'First close');
        $this->assertSame('2026-11-15', $closing->getCutoffDate());
        $this->assertSame('close', $event['action']);
        $this->assertSame('12', $event['actor_id']);
        $this->assertNull($event['previous_cutoff_date']);
        $this->assertSame(0, MonthlyBalance::count());
        $this->assertSame(0, FiscalPeriod::count());
        foreach (['2026-10-31', '2026-11-14', '2026-11-15', '2026-11-15 23:59:59'] as $date) {
            $this->assertLocked(fn () => $this->manual($date));
        }
        $journal = $this->manual('2026-11-16');
        $this->assertSame(JournalStatus::POSTED, $journal->status);
        $this->assertFalse(FiscalPeriod::where('year', 2026)->where('month', 11)->firstOrFail()->is_closed);
        $this->assertSame(1, JournalEntry::count());
        $this->assertSame(0, MonthlyBalance::count());
        $this->assertSame('cutoff_a', DB::getDefaultConnection());
    }

    #[DataProvider('masterModes')]
    public function test_mapped_creation_and_posting_use_actual_transaction_date(bool $shared): void
    {
        $this->prepareDatabases($shared);
        config()->set('accounting.journal.auto_post', false);
        $oldDraft = $this->mapped('2026-11-15');
        $newDraft = $this->mapped('2026-11-16');
        app(ClosingService::class)->closeThroughDate('2026-11-15');
        $this->assertLocked(fn () => $this->mapped('2026-11-15'));
        $this->assertLocked(fn () => app(JournalService::class)->post($oldDraft->id));
        $this->assertSame(JournalStatus::DRAFT, $oldDraft->fresh()->status);
        $this->assertSame(JournalStatus::POSTED, app(JournalService::class)->post($newDraft->id)->status);
        $this->assertSame(2, JournalEntry::count());
    }

    #[DataProvider('masterModes')]
    public function test_both_opening_balance_creation_flows_respect_the_cutoff(bool $shared): void
    {
        $this->prepareDatabases($shared);
        app(ClosingService::class)->closeThroughDate('2026-11-15');
        $data = ['trx_date' => '2026-11-15', 'details' => [
            ['account_id' => Account::where('code', '1001')->value('id'), 'amount' => 100],
            ['account_id' => Account::where('code', '3001')->value('id'), 'amount' => 100],
        ]];
        $this->assertLocked(fn () => app(JournalService::class)->journalOpeningBalance($data));
        $attributes = $this->openingAttributes('1010', '2026-11-15');
        $this->assertLocked(fn () => app(AccountOpeningBalanceService::class)->createAccount($attributes));
        $this->assertFalse(Account::where('code', '1010')->exists());
        $data['trx_date'] = '2026-11-16';
        $this->assertSame(JournalStatus::POSTED, app(JournalService::class)->journalOpeningBalance($data)->status);
        $account = app(AccountOpeningBalanceService::class)->createAccount($this->openingAttributes('1010', '2026-11-16'));
        $this->assertSame('1010', $account->code);
    }

    #[DataProvider('masterModes')]
    public function test_opening_edits_check_old_and_new_dates_and_rollback_master_changes(bool $shared): void
    {
        $this->prepareDatabases($shared);
        $opening = app(AccountOpeningBalanceService::class);
        $account = $opening->createAccount($this->openingAttributes('1010', '2026-11-14'));
        app(ClosingService::class)->closeThroughDate('2026-11-15');
        $this->assertLocked(fn () => $opening->updateAccount($account, [
            'name' => 'Must roll back', 'opening_balance' => 200, 'opening_balance_date' => '2026-11-16',
        ]));
        $this->assertSame('Opening account', $account->fresh()->name);
        $journal = JournalEntry::where('source_id', $account->id)->firstOrFail();
        $this->assertSame('2026-11-14', $journal->trx_date->toDateString());
        $this->assertSame(100.0, (float) $journal->amount);
        app(ClosingService::class)->reopenCutoff(null, 'Correct opening date', 'operator');
        $opening->updateAccount($account->fresh(), ['opening_balance' => 150, 'opening_balance_date' => '2026-11-16']);
        app(ClosingService::class)->closeThroughDate('2026-11-15');
        $this->assertLocked(fn () => $opening->updateAccount($account->fresh(), ['opening_balance_date' => '2026-11-15']));
        $this->assertSame('2026-11-16', $journal->fresh()->trx_date->toDateString());
        $opening->updateAccount($account->fresh(), ['opening_balance' => 175]);
        $this->assertSame(175.0, (float) $journal->fresh()->amount);
    }

    #[DataProvider('masterModes')]
    public function test_reversal_checks_new_date_and_can_correct_a_cutoff_locked_original_in_an_open_month(bool $shared): void
    {
        $this->prepareDatabases($shared);
        $original = $this->manual('2026-11-14');
        app(ClosingService::class)->closeThroughDate('2026-11-15');
        $this->travelTo(Carbon::parse('2026-11-15 23:59:59'));
        $this->assertLocked(fn () => app(JournalService::class)->reverse($original->id, 'Correction'));
        $this->assertSame(1, JournalEntry::count());
        $this->travelTo(Carbon::parse('2026-11-16 00:00:00'));
        $reversal = app(JournalService::class)->reverse($original->id, 'Correction');
        $this->assertSame('2026-11-16', $reversal->trx_date->toDateString());
        $this->assertSame($original->id, $reversal->reversal_of_id);
        $this->assertSame(2, JournalEntry::count());
    }

    public function test_reversal_preserves_original_month_locks_and_checks_the_new_month(): void
    {
        $this->prepareDatabases(false);
        $original = $this->manual('2026-10-14');
        $future = app(FiscalPeriodService::class)->ensureForDate('2026-11-16');
        $future->update(['is_closed' => true]);
        $this->assertLocked(fn () => app(JournalService::class)->reverse($original->id, 'Correction'));
        $future->update(['is_closed' => false]);
        FiscalPeriod::where('year', 2026)->where('month', 10)->update(['is_closed' => true]);
        $this->assertLocked(fn () => app(JournalService::class)->reverse($original->id, 'Correction'));
        $this->assertSame(1, JournalEntry::count());
    }

    public function test_advancing_and_reopening_are_atomic_and_audited(): void
    {
        $this->prepareDatabases(false);
        $closing = app(ClosingService::class);
        $closing->closeThroughDate('2026-11-15', 'alice', 'Initial close');
        $closing->closeThroughDate('2026-11-20', 'bob', 'Advance');
        $this->assertLocked(fn () => $this->manual('2026-11-20'));
        $this->assertInvalid(fn () => $closing->closeThroughDate('2026-11-19'));
        $this->assertInvalid(fn () => $closing->closeThroughDate('2026-11-20'));
        $this->assertInvalid(fn () => $closing->reopenCutoff('2026-11-21', 'Invalid'));
        $this->assertInvalid(fn () => $closing->reopenCutoff('2026-11-10', ''));
        $closing->reopenCutoff('2026-11-15', 'Correct November 16 onward', 'carol');
        $this->assertLocked(fn () => $this->manual('2026-11-15'));
        $this->manual('2026-11-16');
        $closing->reopenCutoff(null, 'Open all cutoff dates', 'dave');
        $this->assertNull($closing->getCutoffDate());
        $this->manual('2026-11-15');
        $history = $closing->getCutoffHistory();
        $this->assertSame(['close', 'advance', 'reopen', 'reopen'], $history->pluck('action')->all());
        $this->assertSame(['alice', 'bob', 'carol', 'dave'], $history->pluck('actor_id')->all());
        $this->assertSame([null, '2026-11-15', '2026-11-20', '2026-11-15'], $history->pluck('previous_cutoff_date')->all());
        $this->assertSame(['2026-11-15', '2026-11-20', '2026-11-15', null], $history->pluck('cutoff_date')->all());
        $this->assertInvalid(fn () => $closing->reopenCutoff(null, 'Already open'));
        $this->assertCount(4, $closing->getCutoffHistory());
    }

    public function test_cutoff_reopening_requires_closed_months_to_be_reopened_latest_first(): void
    {
        $this->prepareDatabases(false);
        $closing = app(ClosingService::class);
        $this->manual('2026-10-14');
        $this->manual('2026-11-14');
        $closing->closeMonth(2026, 10);
        $closing->closeMonth(2026, 11);
        $closing->closeThroughDate('2026-11-30');
        $this->assertInvalid(fn () => $closing->reopenCutoff('2026-10-15', 'Correction'));
        try {
            $closing->reopenMonth(2026, 10);
            $this->fail('Existing monthly dependency must remain enforced.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('subsequent months', $e->getMessage());
        }
        $closing->reopenMonth(2026, 11);
        $closing->reopenMonth(2026, 10);
        $this->assertLocked(fn () => $this->manual('2026-11-20'));
        $closing->reopenCutoff('2026-10-15', 'Correction', 'operator');
        $this->manual('2026-11-20');
        $this->assertLocked(fn () => $this->manual('2026-10-15'));
        $this->assertGreaterThan(0, MonthlyBalance::count());
    }

    public function test_explicit_cutoff_selects_date_closing_without_static_defaults_or_month_snapshots(): void
    {
        $this->prepareDatabases(false);
        $closing = app(ClosingService::class);
        $this->assertSame('2026-11-15', $closing->closeThroughCurrentMonth('operator', '2026-11-15')['cutoff_date']);
        $this->assertSame('2026-11-16', $closing->closeUntilCurrentMonth('operator', '2026-11-16')['cutoff_date']);
        $this->assertSame(0, MonthlyBalance::count());
        $this->assertSame(0, FiscalPeriod::count());
        DB::setDefaultConnection('cutoff_b');
        $this->assertNull($closing->getCutoffDate());
        $this->manual('2026-11-15');
        DB::setDefaultConnection('cutoff_a');
        $this->assertSame('2026-11-16', $closing->getCutoffDate());
        $this->manual('2026-11-17');
    }

    public function test_no_cutoff_retains_full_month_closing_and_existing_reopen_snapshots(): void
    {
        $this->prepareDatabases(false);
        config()->set('accounting.closing.cutoff_enabled', false);
        $this->manual('2026-11-14');
        $this->manual('2026-11-30');
        $closing = app(ClosingService::class);
        $result = $closing->closeThroughCurrentMonth();
        $this->assertCount(1, $result);
        $this->assertTrue($result[0]->is_closed);
        $snapshot = MonthlyBalance::where('account_id', Account::where('code', '1001')->value('id'))->firstOrFail();
        $this->assertSame(200.0, (float) $snapshot->total_debit);
        $this->assertLocked(fn () => $this->manual('2026-11-30'));
        $count = MonthlyBalance::count();
        $closing->reopenMonth(2026, 11);
        $this->assertSame($count, MonthlyBalance::count());
        $this->manual('2026-11-30');
        $this->assertInvalid(fn () => $closing->closeThroughDate('2026-11-15'));
    }

    #[DataProvider('masterModes')]
    public function test_cutoff_and_history_are_isolated_in_each_transaction_database(bool $shared): void
    {
        $this->prepareDatabases($shared);
        $closing = app(ClosingService::class);
        $closing->closeThroughDate('2026-11-15', 'tenant-a');
        DB::setDefaultConnection('cutoff_b');
        $this->assertNull($closing->getCutoffDate());
        $this->assertCount(0, $closing->getCutoffHistory());
        $this->manual('2026-11-15');
        $closing->closeThroughDate('2026-11-10', 'tenant-b');
        $this->manual('2026-11-11');
        DB::setDefaultConnection('cutoff_a');
        $this->assertLocked(fn () => $this->manual('2026-11-11'));
        $this->assertSame('2026-11-15', $closing->getCutoffDate());
        $this->assertSame('tenant-a', $closing->getCutoffHistory()[0]->actor_id);
        $this->assertFalse(Schema::connection('cutoff_master')->hasTable($this->table('closing_cutoffs')));
    }

    public function test_invalid_dates_and_unbalanced_activity_leave_no_cutoff_or_audit_events(): void
    {
        $this->prepareDatabases(false);
        $closing = app(ClosingService::class);
        foreach (['', '2026-02-30', '2026-11-15 12:00:00'] as $date) {
            $this->assertInvalid(fn () => $closing->closeThroughDate($date));
        }
        $journal = JournalEntry::create(['journal_no' => 'UNBALANCED', 'trx_date' => '2026-11-14', 'status' => 'posted']);
        JournalEntryDetail::create(['journal_entry_id' => $journal->id, 'account_id' => Account::where('code', '1001')->value('id'), 'debit' => 1]);
        $this->assertInvalid(fn () => $closing->closeThroughDate('2026-11-15'));
        $this->assertNull($closing->getCutoffDate());
        $this->assertCount(0, $closing->getCutoffHistory());
    }

    public function test_audit_failure_rolls_back_cutoff_and_missing_state_fails_closed(): void
    {
        $this->prepareDatabases(false);
        Schema::drop($this->table('closing_cutoff_events'));
        try {
            app(ClosingService::class)->closeThroughDate('2026-11-15');
            $this->fail('Audit failure must roll back cutoff state.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('closing_cutoff_events', $e->getMessage());
        }
        $this->assertNull(app(ClosingService::class)->getCutoffDate());
        DB::table($this->table('closing_cutoffs'))->delete();
        try {
            $this->manual('2026-11-16');
            $this->fail('Enabled cutoff protection must fail closed without its state row.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cutoff state is missing', $e->getMessage());
        }
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_package_and_connection_prefixes_work_for_cutoff_state_and_journal_queries(): void
    {
        $this->prepareDatabases(true, 'books_', 'host_');
        $this->manual('2026-11-14');
        app(ClosingService::class)->closeThroughDate('2026-11-15');
        $this->assertLocked(fn () => $this->manual('2026-11-15'));
        $this->manual('2026-11-16');
        $this->assertCount(1, app(ClosingService::class)->getCutoffHistory());
    }

    public function test_cutoff_checks_stay_inside_write_transactions_even_without_requested_wrapping(): void
    {
        $this->prepareDatabases(false);
        $levels = [];
        DB::listen(function ($query) use (&$levels) {
            if ($query->connectionName === 'cutoff_a' && str_contains($query->sql, 'closing_cutoffs')
                && str_starts_with(strtolower($query->sql), 'select')) {
                $levels[] = $query->connection->transactionLevel();
            }
        });
        app(ClosingService::class)->closeThroughDate('2026-11-15');
        app(JournalService::class)->journalManual(['trx_date' => '2026-11-16', 'details' => [
            ['account_code' => '1001', 'type' => 'D', 'amount' => 100],
            ['account_code' => '4001', 'type' => 'K', 'amount' => 100],
        ]], false);
        config()->set('accounting.journal.auto_post', false);
        $draft = $this->mapped('2026-11-16');
        app(JournalService::class)->post($draft->id);
        $this->assertCount(4, $levels);
        $this->assertNotContains(0, $levels);
        $this->assertSame(0, DB::connection('cutoff_a')->transactionLevel());
    }

    #[DataProvider('masterModes')]
    public function test_shared_cutoff_closes_only_complete_months_and_enforces_both_tenants(bool $shared): void
    {
        $tenancy = $this->prepareSharedDatabases($shared);
        $runner = app(TenantReportRunner::class);
        $runner->run(['tenant-a', 'tenant-b'], function () {
            $this->manual('2026-10-15');
            $this->manual('2026-11-15');
        });
        $event = app(ClosingService::class)->closeThroughDate('2026-11-15', 1, 'Shared close', ['alpha', 'tenant-a', 'tenant-b']);
        $this->assertSame(['tenant-a', 'tenant-b'], $event['tenant_ids']);
        $this->assertSame('tenant-a', $tenancy->tenant->id);
        $this->assertSame('cutoff_a', DB::getDefaultConnection());
        $runner->run(['tenant-a', 'tenant-b'], function () {
            $this->assertSame('2026-11-15', app(ClosingService::class)->getCutoffDate());
            $this->assertNull(DB::table($this->table('closing_cutoffs'))->value('cutoff_date'));
            $this->assertTrue(FiscalPeriod::where('month', 10)->firstOrFail()->is_closed);
            $this->assertFalse(FiscalPeriod::where('month', 11)->firstOrFail()->is_closed);
            $this->assertSame(0, MonthlyBalance::where('fiscal_month', 11)->count());
            $this->assertGreaterThan(0, MonthlyBalance::where('fiscal_month', 10)->count());
            $this->assertLocked(fn () => $this->manual('2026-11-15 23:59:59'));
            $this->manual('2026-11-16');
            $this->assertFalse(Schema::hasTable($this->table('shared_closing_cutoffs')));
        });
        $this->assertCount(1, app(ClosingService::class)->getCutoffHistory());
    }

    public static function closingPolicies(): array
    {
        return ['legacy timing' => [null], 'following-month day' => [1]];
    }

    #[DataProvider('closingPolicies')]
    public function test_shared_partial_failure_retains_previous_cutoff_and_retry_skips_completed_months(?int $closingDay): void
    {
        $tenancy = $this->prepareSharedDatabases(true);
        config()->set('accounting.closing.closing_day', $closingDay);
        $closing = app(ClosingService::class);
        $closing->closeThroughDate('2026-09-15', 1, null, ['tenant-a', 'tenant-b']);
        app(TenantReportRunner::class)->run(['tenant-a', 'tenant-b'], fn () => $this->manual('2026-10-15'));
        Schema::connection('cutoff_b')->drop($this->table('monthly_balances'));
        DB::setDefaultConnection('testbench'); // Preserve a host connection override, too.
        try {
            $closing->closeThroughDate('2026-11-15', 1);
            $this->fail('Second tenant must fail.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('monthly_balances', $e->getMessage());
        }
        $this->assertSame('testbench', DB::getDefaultConnection());
        $this->assertSame('tenant-a', $tenancy->tenant->id);
        $this->assertSame('2026-09-15', $closing->getCutoffDate());
        $this->assertTrue(FiscalPeriod::on('cutoff_a')->where('month', 10)->firstOrFail()->is_closed);
        $this->assertFalse(FiscalPeriod::on('cutoff_b')->where('month', 10)->firstOrFail()->is_closed);
        $this->assertCount(1, $closing->getCutoffHistory());
        DB::setDefaultConnection('cutoff_b');
        (require $this->migration('2026_01_01_000008_create_acc_monthly_balances_table.php'))->up();
        DB::setDefaultConnection('testbench');
        $event = $closing->closeThroughDate('2026-11-15', 1);
        $retry = $closing->closeThroughDate('2026-11-15', 1, 'Retry', ['tenant-b', 'alpha']);
        $this->assertSame($event['id'], $retry['id']);
        $this->assertCount(2, $closing->getCutoffHistory());
        $this->assertSame('testbench', DB::getDefaultConnection());
        foreach (['cutoff_master', 'cutoff_a', 'cutoff_b'] as $connection) {
            $this->assertSame(0, DB::connection($connection)->transactionLevel());
        }
    }

    public function test_shared_reopen_validates_every_tenant_and_records_one_central_event(): void
    {
        $this->prepareSharedDatabases(false);
        $closing = app(ClosingService::class);
        $closing->closeThroughDate('2026-11-20', 1, null, ['tenant-a', 'tenant-b']);
        app(TenantReportRunner::class)->run(['tenant-b'], fn () => $closing->closeMonth(2026, 11, 1));
        $this->assertInvalid(fn () => $closing->reopenCutoff('2026-11-15', 'Corrections', 1));
        $this->assertSame('2026-11-20', $closing->getCutoffDate());
        $this->assertCount(1, $closing->getCutoffHistory());
        app(TenantReportRunner::class)->run(['tenant-b'], fn () => $closing->reopenMonth(2026, 11));
        $event = $closing->reopenCutoff('2026-11-15', 'Corrections', 1);
        $this->assertSame('reopen', $event['action']);
        app(TenantReportRunner::class)->run(['tenant-a', 'tenant-b'], function () {
            $this->assertLocked(fn () => $this->manual('2026-11-15'));
            $this->manual('2026-11-16');
        });
        $closing->reopenCutoff(null, 'Clear cutoff', 1);
        $this->assertNull($closing->getCutoffDate());
        $this->assertCount(3, $closing->getCutoffHistory());
        $this->assertSame('cutoff_a', DB::getDefaultConnection());
    }

    public function test_shared_scope_and_input_validation_precede_closing_and_context_is_restored(): void
    {
        $tenancy = $this->prepareSharedDatabases(false);
        $closing = app(ClosingService::class);
        foreach ([[], ['missing'], ['tenant-a', 'missing']] as $ids) {
            $this->assertInvalid(fn () => $closing->closeThroughDate('2026-11-15', null, null, $ids));
        }
        $this->assertInvalid(fn () => $closing->closeThroughDate('2026-02-30', null, null, ['tenant-a', 'tenant-b']));
        // Without a persisted scope, the first shared close needs explicit participants.
        $this->assertInvalid(fn () => $closing->closeThroughDate('2026-11-15'));
        $this->assertInvalid(fn () => $closing->closeThroughCurrentMonth());
        $this->assertSame(0, FiscalPeriod::count());
        $this->assertCount(0, $closing->getCutoffHistory());
        $closing->closeThroughDate('2026-11-15', null, null, ['alpha', 'tenant-b']);
        $this->assertInvalid(fn () => $closing->closeThroughDate('2026-11-20', null, null, ['tenant-a']));
        $tenancy->failOn = 'tenant-b';
        try {
            $closing->closeThroughDate('2026-11-20');
            $this->fail('Initialization must fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Tenant initialization failed.', $e->getMessage());
        }
        $this->assertSame('tenant-a', $tenancy->tenant->id);
        $this->assertSame('cutoff_a', DB::getDefaultConnection());
        $this->assertSame('2026-11-15', $closing->getCutoffDate());
        $this->assertSame(0, DB::connection('cutoff_master')->transactionLevel());
    }

    public function test_shared_cutoffs_use_the_master_connection_without_shared_master_data(): void
    {
        $tenancy = $this->prepareSharedDatabases(false);
        $periods = app(FiscalPeriodService::class);
        $closing = app(ClosingService::class);
        $package = require dirname(__DIR__, 2).'/config/accounting.php';
        $this->assertArrayNotHasKey('shared_connection', $package['closing']);
        $this->assertArrayNotHasKey('tenant_ids', $package['closing']);
        config()->set('accounting.closing.shared_connection', 'cutoff_b'); // Removed settings are ignored.
        config()->set('accounting.closing.tenant_ids', ['tenant-a']);
        $this->assertSame('cutoff_master', $periods->cutoffConnection());

        // Master data stays tenant-local; only cutoff state lives on the master connection.
        $closing->closeThroughDate('2026-10-31', 1, null, ['tenant-a', 'tenant-b']);
        $this->assertNull((new Account)->getConnectionName());
        $this->assertFalse(Schema::connection('cutoff_master')->hasTable($this->table('accounts')));
        $this->assertTrue(Schema::connection('cutoff_a')->hasTable($this->table('accounts')));
        $this->assertSame('2026-10-31', DB::connection('cutoff_master')->table($this->table('shared_closing_cutoffs'))->value('cutoff_date'));
        $this->assertSame('2026-10-31', $closing->getCutoffDate());

        // Unsupported: no master connection, or one missing from database.connections.
        foreach ([null, '', 'undefined_master'] as $name) {
            config()->set('accounting.master_data.connection', $name);
            try {
                $closing->closeThroughDate('2026-11-15', 1);
                $this->fail('Shared cutoffs require a configured master connection.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('accounting.master_data.connection', $e->getMessage());
            }
        }

        // Unsupported: a master connection that is also a tenant transaction connection.
        config()->set('accounting.master_data.connection', 'cutoff_b');
        (require dirname(__DIR__, 2).'/database/migrations/accounting-central/2026_11_04_000017_create_shared_closing_cutoffs_tables.php')->up();
        try {
            $closing->closeThroughDate('2026-11-15', 1, null, ['tenant-a', 'tenant-b']);
            $this->fail('A tenant transaction connection cannot store shared cutoffs.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('separate from tenant transaction connections', $e->getMessage());
        }
        $this->assertNull(DB::connection('cutoff_b')->table($this->table('shared_closing_cutoffs'))->value('cutoff_date'));
        $this->assertFalse(FiscalPeriod::on('cutoff_a')->where('month', 11)->where('is_closed', true)->exists());
        $this->assertSame('cutoff_a', DB::getDefaultConnection());
        $this->assertSame('tenant-a', $tenancy->tenant->id);
        foreach (['cutoff_master', 'cutoff_a', 'cutoff_b'] as $connection) {
            $this->assertSame(0, DB::connection($connection)->transactionLevel());
        }
    }

    public function test_shared_manual_mapped_opening_posting_and_reversal_use_effective_date(): void
    {
        $this->prepareSharedDatabases(true);
        config()->set('accounting.journal.auto_post', false);
        $oldDraft = $this->mapped('2026-11-15');
        $newDraft = $this->mapped('2026-11-16');
        $original = $this->manual('2026-11-14');
        app(ClosingService::class)->closeThroughDate('2026-11-15', 1, null, ['tenant-a', 'tenant-b']);
        $this->assertLocked(fn () => $this->mapped('2026-11-15'));
        $this->assertLocked(fn () => app(JournalService::class)->post($oldDraft->id));
        app(JournalService::class)->post($newDraft->id);
        $this->assertLocked(fn () => app(AccountOpeningBalanceService::class)->createAccount($this->openingAttributes('1010', '2026-11-15')));
        $this->assertFalse(Account::where('code', '1010')->exists());
        $data = ['trx_date' => '2026-11-15', 'details' => [
            ['account_id' => Account::where('code', '1001')->value('id'), 'amount' => 100],
            ['account_id' => Account::where('code', '3001')->value('id'), 'amount' => 100],
        ]];
        $this->assertLocked(fn () => app(JournalService::class)->journalOpeningBalance($data));
        $this->travelTo(Carbon::parse('2026-11-15 23:59:59'));
        $this->assertLocked(fn () => app(JournalService::class)->reverse($original->id, 'Correction'));
        $this->travelTo(Carbon::parse('2026-11-16'));
        app(JournalService::class)->reverse($original->id, 'Correction');
        app(AccountOpeningBalanceService::class)->createAccount($this->openingAttributes('1010', '2026-11-16'));
        $this->assertSame(0, DB::connection('cutoff_master')->transactionLevel());
        $this->assertSame(0, DB::connection('cutoff_a')->transactionLevel());
    }

    public function test_shared_lock_outlives_tenant_commit_and_rejects_unprotected_outer_transactions(): void
    {
        $this->prepareSharedDatabases(false);
        app(ClosingService::class)->closeThroughDate('2026-11-15', 1, null, ['tenant-a', 'tenant-b']);
        $levels = [];
        app('events')->listen(TransactionCommitted::class, function ($event) use (&$levels) {
            if ($event->connectionName === 'cutoff_a') {
                $levels[] = DB::connection('cutoff_master')->transactionLevel();
            }
        });
        $this->manual('2026-11-16');
        $this->assertNotEmpty($levels);
        $this->assertNotContains(0, $levels);
        DB::connection('cutoff_master')->beginTransaction(); // An unrelated transaction does not prove the cutoff lock is held.
        DB::connection('cutoff_a')->beginTransaction();
        try {
            $this->manual('2026-11-16');
            $this->fail('Unsafe outer tenant transactions must fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('before starting a tenant transaction', $e->getMessage());
            $this->assertSame(1, DB::connection('cutoff_a')->transactionLevel());
        } finally {
            DB::connection('cutoff_a')->rollBack();
            DB::connection('cutoff_master')->rollBack();
        }
        app(FiscalPeriodService::class)->withClosingLock(fn () => DB::connection('cutoff_a')->transaction(fn () => $this->manual('2026-11-16')));
        $this->assertSame(2, JournalEntry::count());
    }

    public function test_per_tenant_scope_remains_independent_and_audited(): void
    {
        $this->prepareSharedDatabases(false);
        config()->set('accounting.closing.cutoff_mode', 'per_tenant');
        $closing = app(ClosingService::class);
        $events = $closing->closeThroughDate('2026-11-15', 1, null, ['alpha', 'tenant-a', 'tenant-b']);
        $this->assertSame(['tenant-a', 'tenant-b'], array_column($events, 'tenant_id'));
        $closing->closeThroughDate('2026-11-20', 1);
        $closing->reopenCutoff('2026-11-15', 'Tenant A correction', 1);
        app(TenantReportRunner::class)->run(['tenant-b'], function () use ($closing) {
            $this->assertSame('2026-11-15', $closing->getCutoffDate());
            $this->assertCount(1, $closing->getCutoffHistory());
            $closing->reopenCutoff(null, 'Tenant B correction');
        });
        $this->assertSame('2026-11-15', $closing->getCutoffDate());
        $this->assertCount(3, $closing->getCutoffHistory());
        $this->assertNull(DB::connection('cutoff_master')->table($this->table('shared_closing_cutoffs'))->value('cutoff_date'));
    }

    public function test_shared_audit_failure_rolls_back_publication_and_retry_is_safe(): void
    {
        $this->prepareSharedDatabases(false);
        $events = $this->table('shared_closing_cutoff_events');
        Schema::connection('cutoff_master')->rename($events, 'saved_events');
        $closing = app(ClosingService::class);
        try {
            $closing->closeThroughDate('2026-11-15', 1, null, ['tenant-a', 'tenant-b']);
            $this->fail('Audit must succeed before publication.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('shared_closing_cutoff_events', $e->getMessage());
        }
        $this->assertNull($closing->getCutoffDate());
        $this->assertSame('cutoff_a', DB::getDefaultConnection());
        Schema::connection('cutoff_master')->rename('saved_events', $events);
        $closing->closeThroughDate('2026-11-15', 1, null, ['tenant-a', 'tenant-b']);
        $this->assertCount(1, $closing->getCutoffHistory());
    }

    public function test_shared_closing_supports_package_and_connection_prefixes(): void
    {
        $this->prepareSharedDatabases(true, 'books_', 'host_');
        app(TenantReportRunner::class)->run(['tenant-a', 'tenant-b'], fn () => $this->manual('2026-10-15'));
        app(ClosingService::class)->closeThroughDate('2026-11-15', 1, null, ['tenant-a', 'tenant-b']);
        $this->assertSame('2026-11-15', app(ClosingService::class)->getCutoffDate());
        $this->assertTrue(FiscalPeriod::on('cutoff_b')->where('month', 10)->firstOrFail()->is_closed);
    }

    public function test_shared_mode_disabled_preserves_legacy_monthly_behavior_without_central_storage(): void
    {
        $this->prepareDatabases(false);
        config()->set('accounting.closing', ['cutoff_enabled' => false, 'cutoff_mode' => 'shared']);
        $this->manual('2026-11-15');
        $this->assertCount(1, app(ClosingService::class)->closeThroughCurrentMonth());
        $this->assertNull(app(ClosingService::class)->getCutoffDate());
        app(ClosingService::class)->reopenMonth(2026, 11);
        $this->manual('2026-11-15');
        $this->assertSame(2, JournalEntry::count());
    }

    public function test_opening_transaction_initialization_failure_preserves_central_lock_and_rolls_back_owned_levels(): void
    {
        $this->prepareSharedDatabases(true);
        $closing = app(ClosingService::class);
        $closing->closeThroughDate('2026-11-15', 1, null, ['tenant-a', 'tenant-b']);
        $fired = false;
        app('events')->listen(TransactionBeginning::class, function ($event) use (&$fired) {
            if ($event->connectionName === 'cutoff_a' && ! $fired) {
                $fired = true;
                throw new \RuntimeException('Tenant transaction initialization failed.');
            }
        });
        app(FiscalPeriodService::class)->withClosingLock(function () {
            try {
                app(AccountOpeningBalanceService::class)->createAccount($this->openingAttributes('1010', '2026-11-16'));
                $this->fail('Opening transaction initialization must fail.');
            } catch (\RuntimeException $e) {
                $this->assertSame('Tenant transaction initialization failed.', $e->getMessage());
                $this->assertSame(1, DB::connection('cutoff_master')->transactionLevel());
                $this->assertSame(0, DB::connection('cutoff_a')->transactionLevel());
            }
        });
        $this->assertTrue($fired);
        $this->assertFalse(Account::where('code', '1010')->exists());
        $this->assertSame(0, DB::connection('cutoff_master')->transactionLevel());
        $this->assertSame('2026-11-15', $closing->getCutoffDate());
    }

    public function test_shared_scope_membership_and_missing_central_state_fail_closed(): void
    {
        $tenancy = $this->prepareSharedDatabases(false);
        $closing = app(ClosingService::class);
        $closing->closeThroughDate('2026-11-15', 1, null, ['tenant-a', 'tenant-b']);
        $tenancy->end();
        DB::setDefaultConnection('cutoff_a');
        try {
            $this->manual('2026-11-16');
            $this->fail('Shared journal writes require an identified participating tenant.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not in the shared closing scope', $e->getMessage());
        }
        $tenancy->initialize(CutoffTenant::findOrFail('tenant-a'));
        DB::connection('cutoff_master')->table($this->table('shared_closing_cutoffs'))->delete();
        try {
            $this->manual('2026-11-16');
            $this->fail('Missing central state must fail closed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cutoff state is missing', $e->getMessage());
        }
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(0, DB::connection('cutoff_master')->transactionLevel());
    }

    #[DataProvider('masterModes')]
    public function test_closing_day_delays_execution_without_shifting_monthly_movements_or_snapshots(bool $shared): void
    {
        $this->prepareDatabases($shared);
        config()->set('accounting.closing.closing_day', '5'); // Published environment settings are strings.
        $this->travelTo(Carbon::parse('2026-12-04 23:59:59'));
        foreach (['2026-10-31', '2026-11-01', '2026-11-30', '2026-12-01', '2026-12-05', '2026-12-31', '2027-01-01'] as $date) {
            $this->manual($date);
        }
        config()->set('accounting.journal.auto_post', false);
        $this->mapped('2026-11-15'); // Unposted drafts do not enter the snapshot.
        $closing = app(ClosingService::class);
        $closing->closeMonth(2026, 10);
        $this->assertInvalid(fn () => $closing->closeMonth(2026, 11));
        $this->assertSame(0, MonthlyBalance::where('fiscal_month', 11)->count());
        $this->assertFalse(FiscalPeriod::where('year', 2026)->where('month', 11)->firstOrFail()->is_closed);
        $this->travelTo(Carbon::parse('2026-12-05 00:00:00'));
        $period = $closing->closeMonth(2026, 11);
        $this->assertSame('2026-11-01', $period->start_date->toDateString());
        $this->assertSame('2026-11-30', $period->end_date->toDateString());
        $this->assertSame('2026-12-05 00:00:00', $period->closed_at->toDateTimeString());
        $cash = Account::where('code', '1001')->value('id');
        $november = MonthlyBalance::where('account_id', $cash)->where('fiscal_month', 11)->firstOrFail();
        $october = MonthlyBalance::where('account_id', $cash)->where('fiscal_month', 10)->firstOrFail();
        $this->assertSame(200.0, (float) $november->total_debit);
        $this->assertSame(2, $november->journal_count);
        $this->assertSame((float) $october->ending_balance, (float) $november->opening_balance);
        $this->assertFalse(FiscalPeriod::where('year', 2026)->where('month', 12)->firstOrFail()->is_closed);
        $this->assertNull($closing->getCutoffDate());
        $this->assertCount(0, $closing->getCutoffHistory());
        $this->assertLocked(fn () => $this->manual('2026-11-30'));
        $this->manual('2026-12-06'); // Following-month activity remains writable.
        try {
            $closing->closeMonth(2026, 11);
            $this->fail('Direct retries retain the existing already-closed error.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('already closed', $e->getMessage());
        }
        $this->travelTo(Carbon::parse('2027-01-10')); // Late runs are allowed, including year rollover.
        $closing->closeMonth(2026, 12);
        $december = MonthlyBalance::where('account_id', $cash)->where('fiscal_month', 12)->firstOrFail();
        $this->assertSame(400.0, (float) $december->total_debit);
        $this->assertSame((float) $november->ending_balance, (float) $december->opening_balance);
        $closing->reopenMonth(2026, 12);
        $closing->reopenMonth(2026, 11);
        $snapshotCount = MonthlyBalance::count();
        $this->manual('2026-11-30');
        $closing->closeMonth(2026, 11);
        $this->assertSame(300.0, (float) $november->fresh()->total_debit);
        $this->assertSame($snapshotCount, MonthlyBalance::count());
        $this->assertSame(400.0, (float) $december->fresh()->total_debit); // Later snapshots are not silently rebuilt.
        $closing->closeMonth(2026, 12);
        $this->assertSame((float) $november->fresh()->ending_balance, (float) $december->fresh()->opening_balance);
    }

    public static function shortClosingMonths(): array
    {
        return [
            'non-leap February' => [2027, 1, '2027-02-27 23:59:59', '2027-02-28'],
            'leap February' => [2028, 1, '2028-02-28 23:59:59', '2028-02-29'],
            'April' => [2026, 3, '2026-04-29 23:59:59', '2026-04-30'],
            'year rollover' => [2026, 12, '2027-01-30 23:59:59', '2027-01-31'],
        ];
    }

    #[DataProvider('shortClosingMonths')]
    public function test_closing_day_clamps_to_following_month_end(int $year, int $month, string $before, string $due): void
    {
        $this->prepareDatabases(false);
        config()->set('accounting.closing.closing_day', 31);
        $this->travelTo(Carbon::parse($before));
        $this->assertInvalid(fn () => app(ClosingService::class)->closeMonth($year, $month));
        $this->assertSame(0, FiscalPeriod::count());
        $this->travelTo(Carbon::parse($due.' 00:00:00'));
        $period = app(ClosingService::class)->closeMonth($year, $month);
        $this->assertTrue($period->is_closed);
        $this->assertSame($year, $period->year);
        $this->assertSame($month, $period->month);
    }

    public function test_invalid_closing_configuration_and_periods_fail_before_persistence(): void
    {
        $this->prepareDatabases(false);
        foreach ([0, 32, -1, 'bad', 5.5, []] as $day) {
            config()->set('accounting.closing.closing_day', $day);
            $this->assertInvalid(fn () => app(ClosingService::class)->closeMonth(2026, 10));
        }
        config()->set('accounting.closing.closing_day', 5);
        foreach ([[0, 10], [10000, 10], [2026, 0], [2026, 13], ['bad', 11]] as [$year, $month]) {
            $this->assertInvalid(fn () => app(ClosingService::class)->closeMonth($year, $month));
        }
        $this->assertSame(0, FiscalPeriod::count());
        $this->assertSame(0, MonthlyBalance::count());
    }

    public function test_closing_day_bulk_flow_skips_unready_months_and_allows_catch_up_with_cutoff_disabled(): void
    {
        $this->prepareDatabases(false);
        config()->set('accounting.closing.cutoff_enabled', false);
        config()->set('accounting.closing.closing_day', 5);
        foreach (['2026-10-31', '2026-11-30', '2026-12-01'] as $date) {
            $this->manual($date);
        }
        $closing = app(ClosingService::class);
        $this->travelTo(Carbon::parse('2026-12-04 23:59:59'));
        $this->assertSame([10], $closing->closeThroughCurrentMonth()->pluck('month')->all());
        $this->assertCount(0, $closing->closeUntilCurrentMonth());
        $this->assertSame(0, MonthlyBalance::where('fiscal_month', 11)->count());
        $this->travelTo(Carbon::parse('2026-12-05 00:00:00'));
        $this->assertSame([11], $closing->closeUntilCurrentMonth()->pluck('month')->all());
        $this->assertFalse(FiscalPeriod::where('month', 12)->firstOrFail()->is_closed);
        $this->assertCount(0, $closing->closeThroughCurrentMonth());
        $this->assertNull($closing->getCutoffDate());
    }

    #[DataProvider('masterModes')]
    public function test_closing_day_shared_preflight_retry_reopen_and_context_restoration(bool $shared): void
    {
        $tenancy = $this->prepareSharedDatabases($shared);
        config()->set('accounting.closing.closing_day', 5);
        app(TenantReportRunner::class)->run(['tenant-a', 'tenant-b'], function () {
            $this->manual('2026-10-31');
            $this->manual('2026-11-30');
            $this->manual('2026-12-01');
        });
        $closing = app(ClosingService::class);
        $this->travelTo(Carbon::parse('2026-12-04 23:59:59'));
        $this->assertInvalid(fn () => $closing->closeThroughDate('2026-11-30', 1, null, ['tenant-a', 'tenant-b']));
        $this->assertNull($closing->getCutoffDate());
        $this->assertSame('cutoff_a', DB::getDefaultConnection());
        $this->assertSame('tenant-a', $tenancy->tenant->id);
        foreach (['cutoff_a', 'cutoff_b'] as $connection) {
            $this->assertSame(0, FiscalPeriod::on($connection)->where('is_closed', true)->count());
            $this->assertSame(0, MonthlyBalance::on($connection)->count());
        }
        $this->assertSame('2026-10-31', $closing->closeThroughDate('2026-10-31', 1, null, ['tenant-a', 'tenant-b'])['cutoff_date']);
        $this->assertSame('2026-10-31', $closing->closeThroughCurrentMonth(1)['cutoff_date']); // Persisted scope; completed retry.
        $this->travelTo(Carbon::parse('2026-12-05 00:00:00'));
        $event = $closing->closeThroughCurrentMonth(1);
        $this->assertSame('2026-11-30', $event['cutoff_date']);
        $this->assertSame($event['id'], $closing->closeThroughCurrentMonth(1)['id']);
        app(TenantReportRunner::class)->run(['tenant-a', 'tenant-b'], function () use ($closing) {
            $this->assertSame(0, MonthlyBalance::where('fiscal_month', 12)->count());
            $this->assertNull(DB::table($this->table('closing_cutoffs'))->value('cutoff_date'));
            $this->assertLocked(fn () => $this->manual('2026-11-30'));
            $this->manual('2026-12-05');
            $closing->reopenMonth(2026, 11);
        });
        $closing->reopenCutoff('2026-10-31', 'November corrections', 1);
        app(TenantReportRunner::class)->run(['tenant-a', 'tenant-b'], fn () => $this->manual('2026-11-30'));
        $closing->closeThroughDate('2026-11-30', 1);
        $this->assertSame('cutoff_a', DB::getDefaultConnection());
        $this->assertSame('tenant-a', $tenancy->tenant->id);
        $this->assertCount(4, $closing->getCutoffHistory());
        foreach (['cutoff_master', 'cutoff_a', 'cutoff_b'] as $connection) {
            $this->assertSame(0, DB::connection($connection)->transactionLevel());
        }
    }

    public function test_closing_day_does_not_change_independent_cutoff_dates_or_another_tenant(): void
    {
        $this->prepareSharedDatabases(false);
        config()->set('accounting.closing.cutoff_mode', 'per_tenant');
        config()->set('accounting.closing.closing_day', 5);
        $this->travelTo(Carbon::parse('2026-12-04'));
        $closing = app(ClosingService::class);
        $closing->closeThroughDate('2026-11-30'); // Date-only locks have independent semantics.
        $this->assertInvalid(fn () => $closing->closeMonth(2026, 11));
        app(TenantReportRunner::class)->run(['tenant-b'], function () use ($closing) {
            $this->assertNull($closing->getCutoffDate());
            $this->manual('2026-11-30');
        });
        $this->travelTo(Carbon::parse('2026-12-05'));
        $closing->closeMonth(2026, 11);
        $this->assertSame('2026-11-30', $closing->getCutoffDate());
        $this->assertFalse(FiscalPeriod::on('cutoff_b')->where('month', 11)->firstOrFail()->is_closed);
    }

    #[DataProvider('masterModes')]
    public function test_month_end_in_current_month_allows_closing_on_its_last_day(bool $shared): void
    {
        $this->prepareDatabases($shared);
        config()->set('accounting.closing.closing_day', 'month_end');
        config()->set('accounting.closing.closing_day_month', 'current');
        foreach (['2026-11-01', '2026-11-29', '2026-11-30', '2026-12-01'] as $date) {
            $this->manual($date);
        }
        $closing = app(ClosingService::class);
        $this->travelTo(Carbon::parse('2026-11-29 23:59:59'));
        $this->assertInvalid(fn () => $closing->closeMonth(2026, 11));
        $this->assertSame(0, MonthlyBalance::count());
        $this->assertFalse(FiscalPeriod::where('year', 2026)->where('month', 11)->firstOrFail()->is_closed);
        $this->travelTo(Carbon::parse('2026-11-30 00:00:00'));
        $period = $closing->closeMonth(2026, 11);
        $this->assertTrue($period->is_closed);
        $this->assertSame('2026-11-30', $period->end_date->toDateString());
        $cash = Account::where('code', '1001')->value('id');
        $november = MonthlyBalance::where('account_id', $cash)->where('fiscal_month', 11)->firstOrFail();
        $this->assertSame(300.0, (float) $november->total_debit); // Includes November 30, excludes December 1.
        $this->assertSame(3, $november->journal_count);
        $this->assertFalse(FiscalPeriod::where('year', 2026)->where('month', 12)->firstOrFail()->is_closed);
        $this->assertLocked(fn () => $this->manual('2026-11-30'));
        $this->manual('2026-12-02');
        $this->assertNull($closing->getCutoffDate()); // Monthly closing never publishes a cutoff.
    }

    public function test_numeric_day_in_next_month_matches_the_legacy_default(): void
    {
        $this->prepareDatabases(false);
        config()->set('accounting.closing.closing_day', '2');
        $closing = app(ClosingService::class);
        foreach ([null, '', 'next'] as $dayMonth) {
            config()->set('accounting.closing.closing_day_month', $dayMonth);
            $this->travelTo(Carbon::parse('2026-12-01 23:59:59'));
            $this->assertInvalid(fn () => $closing->closeMonth(2026, 11));
        }
        $this->assertSame(0, MonthlyBalance::count());
        $this->travelTo(Carbon::parse('2026-12-02 00:00:00'));
        $this->assertTrue($closing->closeMonth(2026, 11)->is_closed);
    }

    public static function configuredClosingDates(): array
    {
        return [
            'day 31 clamps in current-month leap February' => [31, 'current', 2028, 2, '2028-02-29'],
            'day 31 clamps in current-month November' => [31, 'current', 2026, 11, '2026-11-30'],
            'day 15 in current month' => [15, 'current', 2026, 11, '2026-11-15'],
            'month_end of next month' => ['month_end', 'next', 2026, 11, '2026-12-31'],
            'month_end of next short month' => ['month_end', 'next', 2027, 1, '2027-02-28'],
            'month_end of current December' => ['month_end', 'current', 2026, 12, '2026-12-31'],
            'day 30 clamps in next February' => [30, 'next', 2027, 1, '2027-02-28'],
            'next month rolls into a new year' => ['3', 'next', 2026, 12, '2027-01-03'],
        ];
    }

    #[DataProvider('configuredClosingDates')]
    public function test_configured_closing_dates_clamp_to_the_target_month(int|string $day, string $dayMonth, int $year, int $month, string $due): void
    {
        $this->prepareDatabases(false);
        config()->set('accounting.closing.closing_day', $day);
        config()->set('accounting.closing.closing_day_month', $dayMonth);
        $this->travelTo(Carbon::parse($due.' 00:00:00')->subSecond());
        $this->assertInvalid(fn () => app(ClosingService::class)->closeMonth($year, $month));
        $this->assertSame(0, FiscalPeriod::count());
        $this->travelTo(Carbon::parse($due.' 00:00:00'));
        $period = app(ClosingService::class)->closeMonth($year, $month);
        $this->assertTrue($period->is_closed);
        $this->assertSame([$year, $month], [$period->year, $period->month]);
    }

    public function test_bulk_closing_includes_the_current_month_once_its_configured_day_arrives(): void
    {
        $this->prepareDatabases(false);
        config()->set('accounting.closing.cutoff_enabled', false);
        config()->set('accounting.closing.closing_day', 'month_end');
        config()->set('accounting.closing.closing_day_month', 'current');
        foreach (['2026-10-31', '2026-11-30'] as $date) {
            $this->manual($date);
        }
        $closing = app(ClosingService::class);
        $this->travelTo(Carbon::parse('2026-11-29 23:59:59'));
        $this->assertSame([10], $closing->closeThroughCurrentMonth()->pluck('month')->all());
        $this->travelTo(Carbon::parse('2026-11-30 00:00:00'));
        $this->assertSame([11], $closing->closeUntilCurrentMonth()->pluck('month')->all());
        $this->assertCount(0, $closing->closeThroughCurrentMonth());
        $this->travelTo(Carbon::parse('2026-12-30 23:59:59'));
        $this->assertCount(0, $closing->closeThroughCurrentMonth()); // December is not due until December 31.
        $this->assertFalse(FiscalPeriod::where('month', 12)->firstOrFail()->is_closed);
    }

    #[DataProvider('masterModes')]
    public function test_shared_closing_uses_current_month_end_eligibility(bool $shared): void
    {
        $tenancy = $this->prepareSharedDatabases($shared);
        config()->set('accounting.closing.closing_day', 'month_end');
        config()->set('accounting.closing.closing_day_month', 'current');
        app(TenantReportRunner::class)->run(['tenant-a', 'tenant-b'], function () {
            $this->manual('2026-10-31');
            $this->manual('2026-11-30');
        });
        $closing = app(ClosingService::class);
        $this->travelTo(Carbon::parse('2026-11-29 23:59:59'));
        $this->assertInvalid(fn () => $closing->closeThroughDate('2026-11-30', 1, null, ['tenant-a', 'tenant-b']));
        $this->assertNull($closing->getCutoffDate());
        $this->assertCount(0, $closing->getCutoffHistory());
        foreach (['cutoff_a', 'cutoff_b'] as $connection) {
            $this->assertSame(0, MonthlyBalance::on($connection)->count());
        }
        $this->assertSame('2026-10-31', $closing->closeThroughDate('2026-10-31', 1, null, ['tenant-a', 'tenant-b'])['cutoff_date']);
        $this->travelTo(Carbon::parse('2026-11-30 00:00:00'));
        $event = $closing->closeThroughCurrentMonth(1);
        $this->assertSame('2026-11-30', $event['cutoff_date']);
        $this->assertSame(['tenant-a', 'tenant-b'], $event['tenant_ids']);
        foreach (['cutoff_a', 'cutoff_b'] as $connection) {
            $this->assertTrue(FiscalPeriod::on($connection)->where('month', 11)->firstOrFail()->is_closed);
        }
        $this->assertSame('cutoff_a', DB::getDefaultConnection());
        $this->assertSame('tenant-a', $tenancy->tenant->id);
    }

    public function test_invalid_closing_day_month_configuration_fails_before_persistence(): void
    {
        $this->prepareDatabases(false);
        $this->travelTo(Carbon::parse('2027-06-01'));
        foreach ([['end', 'current'], ['Month_End', 'current'], ['month_end ', 'next'], [0, 'current'], [32, 'next'],
            ['month_end', 'previous'], [5, 'selected'], [5, 'Current'], [5, ['next']], [5, 1]] as [$day, $dayMonth]) {
            config()->set('accounting.closing.closing_day', $day);
            config()->set('accounting.closing.closing_day_month', $dayMonth);
            $this->assertInvalid(fn () => app(ClosingService::class)->closeMonth(2026, 10));
            $this->assertInvalid(fn () => app(ClosingService::class)->closeThroughCurrentMonth());
        }
        $this->assertSame(0, FiscalPeriod::count());
        $this->assertSame(0, MonthlyBalance::count());
        config()->set('accounting.closing.closing_day', null);
        config()->set('accounting.closing.closing_day_month', 'current');
        $this->assertTrue(app(ClosingService::class)->closeMonth(2026, 10)->is_closed); // Null day keeps legacy timing.
    }

    private function prepareSharedDatabases(bool $shared, string $prefix = 'acc_', string $connectionPrefix = ''): CutoffTenancyDouble
    {
        $this->prepareDatabases($shared, $prefix, $connectionPrefix);
        config()->set('accounting.closing.cutoff_mode', 'shared');
        (require dirname(__DIR__, 2).'/database/migrations/accounting-central/2026_11_04_000017_create_shared_closing_cutoffs_tables.php')->up();
        Schema::connection('cutoff_master')->create('cutoff_tenants', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('slug');
            $table->string('connection_name');
        });
        foreach (['tenant-a' => ['alpha', 'cutoff_a'], 'tenant-b' => ['beta', 'cutoff_b']] as $id => [$slug, $connection]) {
            CutoffTenant::create(['id' => $id, 'slug' => $slug, 'connection_name' => $connection]);
        }
        config()->set('tenancy.tenant_model', CutoffTenant::class);
        $tenancy = new CutoffTenancyDouble;
        $this->app->instance(CutoffTenancyDouble::class, $tenancy);
        $this->app->bind(TenantReportRunner::class, fn () => new class extends TenantReportRunner
        {
            protected function tenancy(): object
            {
                return app(CutoffTenancyDouble::class);
            }
        });
        $this->app->instance(FiscalPeriodService::class, new class(app(FiscalPeriodRepository::class)) extends FiscalPeriodService
        {
            protected function currentCutoffTenantId(): ?string
            {
                return app(CutoffTenancyDouble::class)->tenant?->getKey();
            }
        });
        $tenancy->initialize(CutoffTenant::findOrFail('tenant-a'));

        return $tenancy;
    }

    private function assertLocked(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected an accounting date lock.');
        } catch (AccountingPeriodLockedException $e) {
            $this->assertSame(423, $e->getCode());
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('trx_date', $e->errors());
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

    private function manual(string $date): JournalEntry
    {
        return app(JournalService::class)->journalManual(['trx_date' => $date, 'details' => [
            ['account_code' => '1001', 'type' => 'D', 'amount' => 100],
            ['account_code' => '4001', 'type' => 'K', 'amount' => 100],
        ]]);
    }

    private function mapped(string $date): JournalEntry
    {
        return app(JournalService::class)->journalByMapping(['service_code' => 'CUTOFF_TEST', 'trx_date' => $date, 'items' => [
            ['mapping_key' => 'cash', 'amount' => 100], ['mapping_key' => 'revenue', 'amount' => 100],
        ]]);
    }

    private function openingAttributes(string $code, string $date): array
    {
        return ['category_id' => AccountCategory::where('category_code', 'ASSET')->value('id'), 'code' => $code,
            'name' => 'Opening account', 'is_postable' => true, 'status' => true,
            'opening_balance' => 100, 'opening_balance_date' => $date];
    }

    private function prepareDatabases(bool $shared, string $prefix = 'acc_', string $connectionPrefix = ''): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 12:00:00'));
        config()->set('accounting.table_prefix', $prefix);
        config()->set('accounting.master_data.use_shared_database', $shared);
        config()->set('accounting.master_data.connection', 'cutoff_master');
        config()->set('accounting.closing', ['cutoff_enabled' => true, 'cutoff_mode' => 'per_tenant']);
        foreach (['cutoff_master', 'cutoff_a', 'cutoff_b'] as $connection) {
            config()->set('database.connections.'.$connection, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => $connectionPrefix, 'foreign_key_constraints' => true]);
            DB::purge($connection);
        }
        foreach ($shared ? ['cutoff_master'] : ['cutoff_a', 'cutoff_b'] as $connection) {
            DB::setDefaultConnection($connection);
            foreach (['2026_01_01_000001_create_acc_account_categories_table.php', '2026_01_01_000002_create_acc_accounts_table.php', '2026_01_01_000003_create_acc_services_table.php', '2026_01_01_000004_create_acc_service_accounts_table.php'] as $file) {
                (require $this->migration($file))->up();
            }
            foreach (['ASSET', 'REVENUE', 'EQUITY'] as $type) {
                $category = AccountCategory::create(['category_code' => $type, 'category_name' => $type, 'type' => $type, 'report_type' => 'GL']);
                Account::create(['category_id' => $category->id, 'code' => ['ASSET' => '1001', 'REVENUE' => '4001', 'EQUITY' => '3001'][$type], 'name' => $type]);
            }
            $service = Service::create(['service_code' => 'CUTOFF_TEST', 'service_name' => 'Cutoff test', 'module_name' => 'TEST', 'is_active' => true]);
            foreach (['cash' => ['D', '1001'], 'revenue' => ['K', '4001']] as $key => [$position, $code]) {
                ServiceAccount::create(['service_id' => $service->id, 'mapping_key' => $key, 'mapping_name' => $key,
                    'position' => $position, 'account_id' => Account::where('code', $code)->value('id'), 'is_required' => true, 'is_active' => true]);
            }
        }
        foreach (['cutoff_a', 'cutoff_b'] as $connection) {
            DB::setDefaultConnection($connection);
            foreach (['2026_01_01_000005_create_acc_journal_entries_table.php', '2026_01_01_000006_create_acc_journal_entry_details_table.php', '2026_01_01_000007_create_acc_fiscal_periods_table.php', '2026_01_01_000008_create_acc_monthly_balances_table.php', '2026_01_01_000010_add_reversal_fields_to_acc_journal_entries_table.php', '2026_11_04_000016_create_acc_closing_cutoffs_tables.php'] as $file) {
                (require $this->migration($file))->up();
            }
        }
        DB::setDefaultConnection('cutoff_a');
    }

    private function table(string $name): string
    {
        return config('accounting.table_prefix').$name;
    }

    private function migration(string $file): string
    {
        return dirname(__DIR__, 2).'/database/migrations/accounting/'.$file;
    }
}

class CutoffTenant extends Model
{
    protected $connection = 'cutoff_master';

    protected $table = 'cutoff_tenants';

    protected $guarded = [];

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

class CutoffTenancyDouble
{
    public bool $initialized = false;

    public ?CutoffTenant $tenant = null;

    public ?string $failOn = null;

    public function initialize(CutoffTenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->initialized = true;
        DB::setDefaultConnection($tenant->connection_name);
        if ($this->failOn === $tenant->id) {
            throw new \RuntimeException('Tenant initialization failed.');
        }
    }

    public function end(): void
    {
        $this->initialized = false;
        $this->tenant = null;
        DB::setDefaultConnection('testbench');
    }
}
