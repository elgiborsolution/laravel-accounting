<?php

namespace ESolution\LaravelAccounting\Tests\Feature;

use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use ESolution\LaravelAccounting\Models\JournalEntry;
use ESolution\LaravelAccounting\Models\JournalEntryDetail;
use ESolution\LaravelAccounting\Models\MonthlyBalance;
use ESolution\LaravelAccounting\Repositories\AccountCategoryRepository;
use ESolution\LaravelAccounting\Repositories\AccountRepository;
use ESolution\LaravelAccounting\Services\AccountCategoryTreeService;
use ESolution\LaravelAccounting\Services\ConsolidatedReportService;
use ESolution\LaravelAccounting\Services\ReportService;
use ESolution\LaravelAccounting\Support\TenantReportRunner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CategoryRetrievalServiceTest extends TestCase
{
    private object $tenancy;

    private array $categoryIds = [];

    public static function masterModes(): array
    {
        return ['tenant-local master' => [false], 'shared master' => [true]];
    }

    #[DataProvider('masterModes')]
    public function test_active_connection_filters_relations_hierarchy_and_pagination(bool $shared): void
    {
        $this->prepareDatabases($shared);
        DB::setDefaultConnection('category_a');
        request()->headers->set('X-Tenant', 'tenant-a');
        $service = app(AccountCategoryTreeService::class);
        $master = $shared ? 'category_master' : 'category_a';
        $flat = $service->index();
        $this->assertCount($shared ? 7 : 6, $flat);
        $this->assertArrayNotHasKey('children', $flat[0]);
        $this->assertArrayNotHasKey('accounts', $flat[0]);
        $this->assertArrayNotHasKey('balance', $flat[0]);
        $this->assertArrayHasKey('is_active', $flat[0]);
        $this->assertSame(['ASSET', 'EXPENSE'], $service->index(['root_only' => true])->pluck('category_code')->all());
        $this->assertSame(['EXPENSE'], $service->index(['type' => 'eXpEnSe'])->pluck('category_code')->all());
        $this->assertNotContains('EMPTY', $service->index(['has_accounts' => true])->pluck('category_code')->all());
        $children = $service->index(['parent_id' => $this->categoryIds[$master]['ASSET'], 'root_only' => true]);
        $this->assertSame($shared ? ['Z_BANK', 'A_CASH', 'PETTY', 'EMPTY'] : ['Z_BANK', 'A_CASH', 'EMPTY'], $children->pluck('category_code')->all());
        $tree = $service->index(['with' => ['children', 'accounts', 'balance'], 'year' => 2026, 'month' => 7]);
        $this->assertSame($this->categoryIds[$master]['ASSET'], $tree[0]['id']);
        $this->assertSame(155.0, $tree[0]['balance']);
        $this->assertSame(['Z_BANK', 'A_CASH'], array_slice(array_column($tree[0]['children'], 'category_code'), 0, 2));
        $this->assertSame(105.0, $tree[0]['children'][1]['balance']);
        $search = $service->index(['search' => 'Common cash', 'with' => 'children,accounts,balance', 'year' => 2026, 'month' => 7]);
        $this->assertSame(115.0, $search[0]['balance']);
        $this->assertSame([], $search[0]['accounts']);
        $this->assertSame(['A_CASH'], array_column($search[0]['children'], 'category_code'));
        $this->assertSame(['1001'], array_column($search[0]['children'][0]['children'][0]['accounts'], 'code'));
        $page = $service->index(['with' => 'children,balance', 'year' => 2026, 'month' => 7, 'per_page' => 1]);
        $this->assertInstanceOf(LengthAwarePaginator::class, $page);
        $this->assertSame(2, $page->total());
        $this->assertSame(155.0, $page->items()[0]['balance']);
        $this->assertSame([], $this->tenancy->initializations);
        $this->assertSame('category_a', DB::getDefaultConnection());
    }

    #[DataProvider('masterModes')]
    public function test_consolidation_uses_codes_and_visible_accounts_without_double_counting(bool $shared): void
    {
        $this->prepareDatabases($shared);
        DB::setDefaultConnection('category_original');
        $result = app(AccountCategoryTreeService::class)->index([
            'with' => 'children,accounts,balance', 'year' => 2026, 'month' => 7,
        ], ['tenant-a', 'alpha', 'tenant-b', 'tenant-a']);
        $this->assertSame(['ASSET', 'EXPENSE'], $result->pluck('category_code')->all());
        $asset = $result[0];
        $this->assertSame('ASSET', $asset['id']);
        $this->assertSame(453.0, $asset['balance']);
        $this->assertSame(30.0, $asset['accounts'][0]['balance']);
        $this->assertSame(['Z_BANK', 'A_CASH', 'PETTY', 'EMPTY'], array_column($asset['children'], 'category_code'));
        $cash = $asset['children'][1];
        $this->assertSame('ASSET', $cash['parent_id']);
        $this->assertSame(316.0, $cash['balance']);
        $this->assertSame(['1101', '1102'], array_column($cash['accounts'], 'code'));
        $this->assertSame([5.0, 11.0], array_column($cash['accounts'], 'balance'));
        $this->assertSame('CASH_DETAIL', $cash['children'][0]['id']);
        $this->assertSame(300.0, $cash['children'][0]['accounts'][0]['balance']);
        $this->assertSame(7.0, $result[1]['balance']);
        $this->assertSame(['tenant-a', 'tenant-b'], $this->tenancy->initializations);
        $this->assertFalse($this->tenancy->initialized);
        $this->assertSame('category_original', DB::getDefaultConnection());
        if (! $shared) {
            $this->assertNotSame($this->categoryIds['category_a']['ASSET'], $this->categoryIds['category_b']['ASSET']);
        }
    }

    #[DataProvider('masterModes')]
    public function test_consolidated_filters_and_pagination_apply_to_merged_chart(bool $shared): void
    {
        $this->prepareDatabases($shared);
        $service = app(AccountCategoryTreeService::class);
        $ids = ['tenant-a', 'tenant-b'];
        $roots = $service->index(['root_only' => true], $ids);
        $this->assertSame(['ASSET', 'EXPENSE'], $roots->pluck('category_code')->all());
        $this->assertArrayNotHasKey('children', $roots[0]);
        $this->assertArrayNotHasKey('balance', $roots[0]);
        $this->assertCount(6, $service->index(['type' => 'aSsEt'], $ids));
        $this->assertNotContains('EMPTY', $service->index(['has_accounts' => true], $ids)->pluck('category_code')->all());
        foreach (['ASSET', $this->categoryIds[$shared ? 'category_master' : 'category_a']['ASSET'], $this->categoryIds[$shared ? 'category_master' : 'category_b']['ASSET']] as $parent) {
            $children = $service->index(['parent_id' => $parent, 'with' => ['children', 'accounts']], $ids);
            $this->assertSame(['Z_BANK', 'A_CASH', 'PETTY', 'EMPTY'], $children->pluck('category_code')->all());
            $this->assertArrayNotHasKey('balance', $children[0]);
            $this->assertArrayNotHasKey('balance', collect($children[0]['accounts'])->first());
        }
        $page = $service->index(['tenant_ids' => $ids, 'parent_id' => 'ASSET', 'page' => 2, 'per_page' => 2]);
        $this->assertSame(4, $page->total());
        $this->assertSame(['PETTY', 'EMPTY'], collect($page->items())->pluck('category_code')->all());
        $treePage = $service->index(['with' => 'children,accounts,balance', 'year' => 2026, 'month' => 7, 'per_page' => 1], $ids);
        $this->assertSame(2, $treePage->total());
        $this->assertSame(453.0, $treePage->items()[0]['balance']);
        $this->assertCount(4, $treePage->items()[0]['children']);
        $search = $service->index(['search' => 'Common cash', 'with' => 'children,accounts,balance', 'year' => 2026, 'month' => 7], $ids);
        $this->assertCount(1, $search);
        $this->assertSame(346.0, $search[0]['balance']);
        $this->assertSame([], $search[0]['accounts']);
        $this->assertSame(['A_CASH'], array_column($search[0]['children'], 'category_code'));
        $this->assertSame(['1001'], array_column($search[0]['children'][0]['children'][0]['accounts'], 'code'));
    }

    public function test_balance_fallback_and_tenant_period_isolation(): void
    {
        $this->prepareDatabases(false);
        $account = Account::on('category_b')->where('code', '1001')->firstOrFail();
        MonthlyBalance::on('category_b')->where('account_id', $account->id)->where('fiscal_month', 7)->delete();
        $journal = JournalEntry::on('category_b')->create(['journal_no' => 'CATEGORY-LIVE', 'trx_date' => '2026-07-15', 'status' => 'posted']);
        JournalEntryDetail::on('category_b')->create(['journal_entry_id' => $journal->id, 'account_id' => $account->id, 'debit' => 200, 'credit' => 0]);
        $service = app(AccountCategoryTreeService::class);
        foreach ([[7, ['tenant-a', 'tenant-b'], 453.0], [8, ['tenant-a', 'tenant-b'], 4530.0], [7, ['tenant-a'], 155.0]] as [$month, $ids, $expected]) {
            $items = $service->index(['with' => 'children,balance', 'year' => 2026, 'month' => $month], $ids);
            $this->assertSame($expected, $items[0]['balance']);
            $this->assertArrayNotHasKey('accounts', $items[0]);
        }
        Schema::connection('category_b')->drop('acc_monthly_balances');
        $this->assertCount(2, $service->index(['with' => 'children,accounts'], ['tenant-a', 'tenant-b']));
    }

    public static function invalidLists(): array
    {
        return ['empty' => [[]], 'missing' => [['missing']], 'valid then missing' => [['tenant-a', 'missing']], 'blank' => [[' ']], 'nested' => [[['tenant-a']]], 'non-list' => [['first' => 'tenant-a']]];
    }

    #[DataProvider('invalidLists')]
    public function test_invalid_tenants_are_rejected_before_execution(array $ids): void
    {
        $this->prepareDatabases(false);
        DB::setDefaultConnection('category_original');
        try {
            app(AccountCategoryTreeService::class)->index(['with' => 'balance'], $ids);
            $this->fail('Invalid tenants must not produce partial results.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
        $this->assertSame([], $this->tenancy->initializations);
        $this->assertSame('category_original', DB::getDefaultConnection());
    }

    #[DataProvider('masterModes')]
    public function test_success_and_query_failure_restore_active_tenant_and_connection_override(bool $shared): void
    {
        $this->prepareDatabases($shared);
        $original = CategoryRetrievalTenant::findOrFail('original');
        $this->tenancy->initialize($original);
        DB::setDefaultConnection('testbench');
        $service = app(AccountCategoryTreeService::class);
        $service->index(['with' => 'balance', 'year' => 2026, 'month' => 7], ['tenant-a', 'tenant-b']);
        $this->assertSame($original, $this->tenancy->tenant);
        $this->assertSame('testbench', DB::getDefaultConnection());
        Schema::connection('category_b')->drop('acc_monthly_balances');
        try {
            $service->index(['with' => 'balance'], ['tenant-a', 'tenant-b']);
            $this->fail('Tenant query errors must propagate.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('acc_monthly_balances', $e->getMessage());
        }
        $this->assertSame($original, $this->tenancy->tenant);
        $this->assertSame('testbench', DB::getDefaultConnection());
    }

    public function test_initialization_failure_restores_inactive_context(): void
    {
        $this->prepareDatabases(false);
        $this->tenancy->failOn = 'tenant-b';
        DB::setDefaultConnection('category_original');
        try {
            app(AccountCategoryTreeService::class)->index([], ['tenant-a', 'tenant-b']);
            $this->fail('Tenant initialization errors must propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('Tenant initialization failed.', $e->getMessage());
        }
        $this->assertFalse($this->tenancy->initialized);
        $this->assertSame('category_original', DB::getDefaultConnection());
    }

    public function test_incompatible_definitions_and_invalid_type_leave_context_restored(): void
    {
        $this->prepareDatabases(false);
        DB::setDefaultConnection('category_original');
        $service = app(AccountCategoryTreeService::class);
        foreach ([['tenant_ids' => null], ['tenant_ids' => ['tenant-a'], 'type' => 'invalid']] as $filters) {
            try {
                $service->index($filters);
                $this->fail('Invalid filters must fail.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        Account::on('category_b')->where('code', '1001')->update(['category_id' => $this->categoryIds['category_b']['Z_BANK']]);
        try {
            $service->index([], ['tenant-a', 'tenant-b']);
            $this->fail('Incompatible code definitions must fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('consolidation', $e->errors());
        }
        $this->assertFalse($this->tenancy->initialized);
        $this->assertSame('category_original', DB::getDefaultConnection());
    }

    public function test_explicit_repository_connections_remain_in_effect(): void
    {
        $this->prepareDatabases(true);
        config()->set('accounting.master_data.use_shared_database', false);
        $accounts = $this->mock(AccountRepository::class)->makePartial();
        $accounts->shouldReceive('visibleOrdered')->twice()->andReturnUsing(fn ($tenantId) => Account::on('category_master')
            ->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))->orderBy('code')->get());
        $accounts->shouldReceive('findManyByIds')->twice()->andReturnUsing(fn ($ids) => Account::on('category_master')->whereIn('id', $ids)->get()->keyBy('id'));
        $categories = $this->mock(AccountCategoryRepository::class)->makePartial();
        $categories->shouldReceive('allOrdered')->times(4)->andReturnUsing(fn () => AccountCategory::on('category_master')->orderBy('sequence_no')->orderBy('category_name')->get());
        $items = app(AccountCategoryTreeService::class)->index(['with' => 'children,balance', 'year' => 2026, 'month' => 7], ['tenant-a', 'tenant-b']);
        $this->assertSame(453.0, $items[0]['balance']);
        $this->assertSame(7.0, $items[1]['balance']);
        $this->assertSame('testbench', DB::getDefaultConnection());
    }

    public function test_numeric_codes_keep_identity_and_lexical_account_order(): void
    {
        $this->prepareDatabases(false);
        foreach (['category_a', 'category_b'] as $connection) {
            foreach (['01', '1'] as $code) {
                $parent = AccountCategory::on($connection)->create([
                    'category_code' => $code, 'category_name' => 'Group '.$code, 'type' => 'ASSET', 'report_type' => 'BS',
                ]);
                AccountCategory::on($connection)->create([
                    'category_code' => 'CHILD-'.$code, 'category_name' => 'Child '.$code,
                    'parent_id' => $parent->id, 'type' => 'ASSET', 'report_type' => 'BS',
                ]);
                if ($code === '01') {
                    foreach (['22', '110'] as $accountCode) {
                        Account::on($connection)->create(['category_id' => $parent->id, 'code' => $accountCode, 'name' => 'Account '.$accountCode]);
                    }
                }
            }
        }
        $service = app(AccountCategoryTreeService::class);
        $children = $service->index(['parent_id' => '01'], ['tenant-a', 'tenant-b']);
        $items = $service->index(['with' => 'children,accounts'], ['tenant-a', 'tenant-b']);
        $leadingZero = $items->firstWhere('category_code', '===', '01');
        $this->assertSame(['CHILD-01'], $children->pluck('category_code')->all());
        $this->assertSame('01', $leadingZero['id']);
        $this->assertSame(['110', '22'], collect($leadingZero['accounts'])->pluck('code')->all());
    }

    public function test_ordering_uses_first_selected_tenant_sequence(): void
    {
        $this->prepareDatabases(false);
        AccountCategory::on('category_b')->where('category_code', 'Z_BANK')->update(['sequence_no' => 35]);
        $service = app(AccountCategoryTreeService::class);
        $firstA = $service->index(['with' => 'children'], ['tenant-a', 'tenant-b']);
        $firstB = $service->index(['with' => 'children'], ['tenant-b', 'tenant-a']);
        $this->assertSame(['Z_BANK', 'A_CASH', 'PETTY', 'EMPTY'], array_column($firstA[0]['children'], 'category_code'));
        $this->assertSame(['A_CASH', 'PETTY', 'Z_BANK', 'EMPTY'], array_column($firstB[0]['children'], 'category_code'));
    }

    public function test_active_connection_without_tenancy_and_fresh_balance_reads(): void
    {
        $this->prepareDatabases(false);
        config()->set('tenancy.tenant_model', null);
        $service = app(AccountCategoryTreeService::class);
        $filters = ['with' => 'children,balance', 'year' => 2026, 'month' => 7];
        DB::setDefaultConnection('category_a');
        $this->assertSame(150.0, $service->index($filters)[0]['balance']);
        DB::setDefaultConnection('category_b');
        $this->assertSame(287.0, $service->index($filters)[0]['balance']);
        $account = Account::on('category_b')->where('code', '1001')->firstOrFail();
        MonthlyBalance::on('category_b')->where('account_id', $account->id)->where('fiscal_month', 7)->update(['ending_balance' => 250]);
        $this->assertSame(337.0, $service->index($filters)[0]['balance']);
        $this->assertSame([], $this->tenancy->initializations);
    }

    public function test_unreachable_hierarchy_is_rejected_after_restoration(): void
    {
        $this->prepareDatabases(false);
        AccountCategory::on('category_b')->where('category_code', 'A_CASH')->update(['parent_id' => $this->categoryIds['category_b']['CASH_DETAIL']]);
        DB::setDefaultConnection('category_original');
        try {
            app(AccountCategoryTreeService::class)->index([], ['tenant-a', 'tenant-b']);
            $this->fail('An unreachable source branch must not silently disappear.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('unreachable', $e->getMessage());
        }
        $this->assertSame('category_original', DB::getDefaultConnection());
        $this->assertFalse($this->tenancy->initialized);
    }

    private function prepareDatabases(bool $shared): void
    {
        foreach (['category_registry', 'category_master', 'category_a', 'category_b', 'category_original'] as $connection) {
            config()->set('database.connections.'.$connection, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
            DB::purge($connection);
        }
        Schema::connection('category_registry')->create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('slug')->unique();
            $table->string('connection_name');
        });
        foreach (['tenant-a' => 'category_a', 'tenant-b' => 'category_b', 'original' => 'category_original'] as $id => $connection) {
            CategoryRetrievalTenant::create(['id' => $id, 'slug' => $id === 'tenant-a' ? 'alpha' : $id, 'connection_name' => $connection]);
        }
        config()->set('tenancy.tenant_model', CategoryRetrievalTenant::class);
        config()->set('accounting.master_data.use_shared_database', $shared);
        config()->set('accounting.master_data.connection', 'category_master');
        foreach ($shared ? ['category_master'] : ['category_a', 'category_b'] as $master) {
            DB::setDefaultConnection($master);
            foreach (['2026_01_01_000001_create_acc_account_categories_table.php', '2026_01_01_000002_create_acc_accounts_table.php', '2026_01_01_000003_create_acc_services_table.php', '2026_06_30_000011_add_parent_id_to_acc_account_categories_table.php'] as $migration) {
                (require $this->migration($migration))->up();
            }
            foreach (['ASSET' => [null, 10, 'Assets'], 'Z_BANK' => ['ASSET', 10, 'Bank'], 'A_CASH' => ['ASSET', 20, 'Cash'], 'CASH_DETAIL' => ['A_CASH', 5, 'Main cash'], 'EMPTY' => ['ASSET', 40, 'Empty branch'], 'EXPENSE' => [null, 30, 'Expenses'], 'PETTY' => ['ASSET', 25, 'Petty cash']] as $code => [$parent, $sequence, $name]) {
                if (! $shared && $master === 'category_a' && $code === 'PETTY') {
                    continue;
                }
                $category = AccountCategory::on($master)->create(['parent_id' => $parent ? $this->categoryIds[$master][$parent] : null,
                    'category_code' => $code, 'category_name' => $name, 'sequence_no' => $sequence,
                    'type' => $code === 'EXPENSE' ? 'EXPENSE' : 'ASSET', 'report_type' => $code === 'EXPENSE' ? 'PL' : 'BS']);
                $this->categoryIds[$master][$code] = $category->id;
            }
            foreach (['1000' => ['ASSET', null, 'Direct root'], '1001' => ['CASH_DETAIL', null, 'Common cash'], '1002' => ['Z_BANK', null, 'Bank account'], '1101' => ['A_CASH', 'tenant-a', 'Tenant a cash'], '1102' => ['A_CASH', 'tenant-b', 'Tenant b cash'], '9999' => ['EMPTY', 'tenant-c', 'Hidden account'], '5001' => ['EXPENSE', null, 'Expense'], '1003' => ['PETTY', null, 'Petty account']] as $code => [$category, $owner, $name]) {
                if (! isset($this->categoryIds[$master][$category])) {
                    continue;
                }
                Account::on($master)->create(['category_id' => $this->categoryIds[$master][$category], 'code' => (string) $code, 'name' => $name, 'description' => 'Notes '.$code, 'tenant_id' => $owner]);
            }
        }
        foreach (['category_a' => ['1000' => 10, '1001' => 100, '1002' => 40, '1101' => 5, '1102' => 9000, '9999' => 10000, '5001' => 3, '1003' => 0], 'category_b' => ['1000' => 20, '1001' => 200, '1002' => 60, '1101' => 8000, '1102' => 11, '9999' => 20000, '5001' => 4, '1003' => 7]] as $connection => $amounts) {
            DB::setDefaultConnection($connection);
            foreach (['2026_01_01_000005_create_acc_journal_entries_table.php', '2026_01_01_000006_create_acc_journal_entry_details_table.php', '2026_01_01_000007_create_acc_fiscal_periods_table.php', '2026_01_01_000008_create_acc_monthly_balances_table.php'] as $migration) {
                (require $this->migration($migration))->up();
            }
            $accounts = Account::on($shared ? 'category_master' : $connection)->get()->keyBy('code');
            foreach ($amounts as $code => $amount) {
                if (! $accounts->has($code)) {
                    continue;
                }
                foreach ([7 => 1, 8 => 10] as $month => $factor) {
                    MonthlyBalance::on($connection)->create(['fiscal_year' => 2026, 'fiscal_month' => $month, 'account_id' => $accounts->get($code)->id, 'ending_balance' => $amount * $factor]);
                }
            }
        }
        DB::setDefaultConnection('testbench');
        $this->tenancy = new class
        {
            public bool $initialized = false;

            public ?Model $tenant = null;

            public array $initializations = [];

            public ?string $failOn = null;

            public function initialize(Model $tenant): void
            {
                $this->initializations[] = $tenant->getKey();
                $this->tenant = $tenant;
                $this->initialized = true;
                DB::setDefaultConnection($tenant->connection_name);
                if ($this->failOn === $tenant->getKey()) {
                    throw new RuntimeException('Tenant initialization failed.');
                }
            }

            public function end(): void
            {
                $this->tenant = null;
                $this->initialized = false;
                DB::setDefaultConnection('testbench');
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
        $this->app->singleton(ConsolidatedReportService::class, fn ($app) => new CategoryConsolidationService(
            $app->make(ReportService::class), $app->make(TenantReportRunner::class), $app->make(AccountCategoryRepository::class), $app->make(AccountRepository::class), $this->tenancy
        ));
    }

    private function migration(string $filename): string
    {
        return dirname(__DIR__, 2).'/database/migrations/accounting/'.$filename;
    }
}

class CategoryRetrievalTenant extends Model
{
    protected $connection = 'category_registry';

    protected $table = 'tenants';

    protected $guarded = [];

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

// Inject the same host-tenancy double into the existing lifecycle runner and
// the category visibility lookup, without installing a global helper.
class CategoryConsolidationService extends ConsolidatedReportService
{
    public function __construct(ReportService $reports, TenantReportRunner $tenants, AccountCategoryRepository $categories, AccountRepository $accounts, private object $hostTenancy)
    {
        parent::__construct($reports, $tenants, $categories, $accounts);
    }

    protected function categoryTenantIdentifier(): string
    {
        return (string) $this->hostTenancy->tenant->getKey();
    }
}
