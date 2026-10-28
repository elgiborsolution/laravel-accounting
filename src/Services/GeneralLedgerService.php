<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Enums\JournalStatus;
use ESolution\LaravelAccounting\Repositories\AccountCategoryRepository;
use ESolution\LaravelAccounting\Repositories\AccountRepository;
use ESolution\LaravelAccounting\Support\AccountingConnectionResolver;
use ESolution\LaravelAccounting\Support\AccountingTableResolver;
use ESolution\LaravelAccounting\Support\GeneralLedgerPaginator;
use ESolution\LaravelAccounting\Support\TenantReportRunner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class GeneralLedgerService
{
    public function __construct(
        protected AccountRepository $accounts,
        protected AccountCategoryRepository $categories,
        protected AccountBalanceService $balances,
        protected AccountingConnectionResolver $connections,
        protected AccountingTableResolver $tables
    ) {
    }

    /**
     * Return an account ledger for a complete monthly accounting period.
     */
    public function getLedger(string $accountId, int $year, int $month): array
    {
        $ledger = $this->resolveLedger($accountId, $year, $month);

        return $ledger['summary'];
    }

    /**
     * Return the native paginator for General Ledger detail rows. Running
     * balances and summary totals are calculated from the full period first.
     */
    public function getLedgerDetails(
        string $accountId,
        int $year,
        int $month,
        string|int|null $tenant = null,
        ?int $page = null,
        ?int $perPage = null
    ): GeneralLedgerPaginator {
        // Keep existing positional page/per-page calls working while allowing
        // direct consumers to pass a tenant identifier before pagination.
        if (is_int($tenant)) {
            $perPage = $page;
            $page = $tenant;
            $tenant = null;
        }

        return $this->runForTenant($tenant, function () use ($accountId, $year, $month, $page, $perPage) {
            $ledger = $this->resolveLedger($accountId, $year, $month);
            $details = collect($this->getDetails(
                $accountId,
                $ledger['period_start']->toDateString(),
                $ledger['period_end']->toDateString(),
                $ledger['summary']['opening_balance'],
                $ledger['is_debit_normal']
            ));
            $page = max(1, $page ?? 1);
            $perPage = max(1, $perPage ?? 15);

            $paginator = new GeneralLedgerPaginator(
                $details->forPage($page, $perPage)->values(),
                $details->count(),
                $perPage,
                $page,
                ['path' => request()->url(), 'pageName' => 'page'],
                $ledger['summary']
            );

            $paginator->appends(request()->query());

            return $paginator;
        });
    }

    /**
     * Load posted journal movements and calculate the running account balance.
     */
    public function getDetails(
        string $accountId,
        string $startDate,
        string $endDate,
        float $openingBalance,
        bool $isDebitNormal
    ): array {
        $connection = $this->connections->resolveTransactionDataConnection();
        $prefix = $this->tables->tablePrefix();
        $detailsTable = $prefix.'journal_entry_details';
        $journalsTable = $prefix.'journal_entries';

        $movements = DB::connection($connection)
            ->table($detailsTable)
            ->join($journalsTable, $journalsTable.'.id', '=', $detailsTable.'.journal_entry_id')
            ->where($detailsTable.'.account_id', $accountId)
            ->whereBetween($journalsTable.'.trx_date', [$startDate, $endDate])
            ->where($journalsTable.'.status', JournalStatus::POSTED->value)
            ->orderBy($journalsTable.'.trx_date')
            ->orderBy($journalsTable.'.journal_no')
            ->orderBy($detailsTable.'.created_at')
            ->select([
                $journalsTable.'.trx_date',
                $journalsTable.'.journal_no',
                $journalsTable.'.reference_no',
                $journalsTable.'.description as journal_description',
                $detailsTable.'.description as detail_description',
                $detailsTable.'.debit',
                $detailsTable.'.credit',
            ])
            ->get();

        $runningBalance = $openingBalance;

        return $movements->map(function (object $movement) use (&$runningBalance, $isDebitNormal) {
            $debit = (float) $movement->debit;
            $credit = (float) $movement->credit;
            $runningBalance = $this->balances->applyMovement($runningBalance, $debit, $credit, $isDebitNormal);

            return [
                'date' => Carbon::parse($movement->trx_date)->toDateString(),
                'journal_number' => $movement->journal_no,
                'reference_no' => $movement->reference_no,
                'description' => $movement->detail_description ?: $movement->journal_description,
                'debit' => round($debit, 2),
                'credit' => round($credit, 2),
                'ending_balance' => round($runningBalance, 2),
            ];
        })->all();
    }

    /**
     * Inspect period activity for the same account code in each selected tenant.
     * Missing accounts are errors, rather than zero-activity ledger balances.
     */
    public function getAccountBreakdown(string $accountCode, string $startDate, string $endDate, array $tenantIds): array
    {
        validator(['account_code' => $accountCode, 'start_date' => $startDate, 'end_date' => $endDate], [
            'account_code' => ['required', 'string'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ])->validate();

        $sources = app(TenantReportRunner::class)->run($tenantIds, function () use ($accountCode, $startDate, $endDate) {
            $account = $this->accounts->findByCode($accountCode);
            if (! $account) {
                return ['error' => 'missing_account'];
            }
            $category = $this->categories->findById($account->category_id);
            $type = strtoupper((string) $category?->type);
            if (! in_array($type, ['ASSET', 'EXPENSE', 'LIABILITY', 'EQUITY', 'REVENUE'], true)) {
                return ['error' => 'unknown_normal_balance'];
            }
            $debitNormal = $this->isDebitNormal($type);

            return [
                'account' => [
                    'code' => $account->code,
                    'name' => $account->name,
                    'category' => [
                        'category_code' => $category->category_code,
                        'category_name' => $category->category_name,
                        'type' => $type,
                    ],
                    'normal_balance' => $debitNormal ? 'D' : 'K',
                ],
                'amounts' => $this->accountActivityTotals($account->id, $startDate, $endDate, $debitNormal),
            ];
        });

        // The runner has restored the caller context before metadata checks or
        // aggregation can raise an error. Never return incomplete overall totals.
        $account = null;
        $errors = [];
        $tenants = [];
        foreach ($sources as $source) {
            $tenantId = $source['tenant_id'];
            $result = $source['report'];
            if (($result['error'] ?? null) === 'missing_account') {
                $errors['account_code'][] = 'Account ['.$accountCode.'] was not found in tenant ['.$tenantId.'].';
                continue;
            }
            if (($result['error'] ?? null) === 'unknown_normal_balance') {
                $errors['normal_balance'][] = 'Account ['.$accountCode.'] has an unresolved normal-balance classification in tenant ['.$tenantId.'].';
                continue;
            }
            $account ??= $result['account'];
            if ($result['account']['normal_balance'] !== $account['normal_balance']) {
                $errors['normal_balance'][] = 'Account ['.$accountCode.'] has an incompatible normal balance in tenant ['.$tenantId.'].';
            }
            $tenants[] = ['tenant_id' => $tenantId] + $result['amounts'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $debit = round(array_sum(array_column($tenants, 'total_debit')), 2);
        $credit = round(array_sum(array_column($tenants, 'total_credit')), 2);
        $debitNormal = $account['normal_balance'] === 'D';

        return [
            'account' => $account,
            'period' => ['start_date' => $startDate, 'end_date' => $endDate],
            'tenant_ids' => array_column($sources, 'tenant_id'),
            'tenants' => $tenants,
            'totals' => [
                'total_debit' => $debit,
                'total_credit' => $credit,
                'net_amount' => round($this->balances->applyMovement(0.0, $debit, $credit, $debitNormal), 2),
            ],
            'calculation_basis' => $debitNormal ? 'debit_minus_credit' : 'credit_minus_debit',
        ];
    }

    protected function accountActivityTotals(string $accountId, string $startDate, string $endDate, bool $debitNormal): array
    {
        $connection = DB::connection($this->connections->resolveTransactionDataConnection());
        $details = $this->tables->table('journal_entry_details');
        $journals = $this->tables->table('journal_entries');
        $grammar = $connection->getQueryGrammar();
        $totals = $connection->table($details)
            ->join($journals, $journals.'.id', '=', $details.'.journal_entry_id')
            ->where($details.'.account_id', $accountId)
            ->whereDate($journals.'.trx_date', '>=', $startDate)
            ->whereDate($journals.'.trx_date', '<=', $endDate)
            ->where($journals.'.status', JournalStatus::POSTED->value)
            ->where(function ($query) use ($journals) {
                $query->whereNull($journals.'.source_type')
                    ->orWhereNotIn($journals.'.source_type', ['OPENING_BALANCE', AccountOpeningBalanceService::SOURCE_TYPE]);
            })
            ->selectRaw('SUM('.$grammar->wrap($details.'.debit').') as total_debit')
            ->selectRaw('SUM('.$grammar->wrap($details.'.credit').') as total_credit')
            ->first();
        $debit = round((float) ($totals->total_debit ?? 0), 2);
        $credit = round((float) ($totals->total_credit ?? 0), 2);

        return [
            'total_debit' => $debit,
            'total_credit' => $credit,
            'net_amount' => round($this->balances->applyMovement(0.0, $debit, $credit, $debitNormal), 2),
            'balance_side' => $debit > $credit ? 'D' : ($credit > $debit ? 'K' : null),
        ];
    }

    protected function resolveLedger(string $accountId, int $year, int $month): array
    {
        $account = $this->accounts->findById($accountId);

        if (! $account) {
            abort(404);
        }

        $category = $this->categories->findById($account->category_id);
        $periodStart = Carbon::create($year, $month, 1)->startOfDay();
        $periodEnd = $periodStart->copy()->endOfMonth()->endOfDay();
        $balance = $this->balances->getBalances([$accountId], $year, $month)->get($accountId, [
            'opening_balance' => 0.0,
            'total_debit' => 0.0,
            'total_credit' => 0.0,
            'ending_balance' => 0.0,
        ]);

        return [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'is_debit_normal' => $this->isDebitNormal($category?->type),
            'summary' => [
                'account' => array_merge($account->toArray(), [
                    'category_path' => $category
                        ? $this->categories->buildLineage($category)->pluck('category_name')->all()
                        : [],
                ]),
                'period' => [
                    'year' => $year,
                    'month' => $month,
                    'start_date' => $periodStart->toDateString(),
                    'end_date' => $periodEnd->toDateString(),
                ],
                'opening_balance' => (float) $balance['opening_balance'],
                'total_debit' => (float) $balance['total_debit'],
                'total_credit' => (float) $balance['total_credit'],
                'ending_balance' => (float) $balance['ending_balance'],
            ],
        ];
    }

    /**
     * Run the ledger lookup in an explicitly requested Stancl tenant context,
     * then restore the tenant that was active before the service call.
     */
    protected function runForTenant(?string $tenantIdentifier, callable $callback): mixed
    {
        if ($tenantIdentifier === null || $tenantIdentifier === '') {
            return $callback();
        }

        if (! function_exists('tenancy')) {
            throw new RuntimeException('Tenant override requires an initialized tenancy integration.');
        }

        $tenantModel = config('tenancy.tenant_model');

        if (! is_string($tenantModel) || ! class_exists($tenantModel)) {
            throw new RuntimeException('Tenant override requires a valid tenancy.tenant_model configuration.');
        }

        $tenant = $this->findTenantModel($tenantModel, $tenantIdentifier);

        if (! $tenant) {
            throw new RuntimeException("Tenant [{$tenantIdentifier}] could not be resolved.");
        }

        $tenancy = tenancy();
        $wasInitialized = (bool) ($tenancy->initialized ?? false);
        $previousTenant = $wasInitialized ? ($tenancy->tenant ?? null) : null;

        try {
            $tenancy->initialize($tenant);

            return $callback();
        } finally {
            if ($wasInitialized && $previousTenant) {
                $tenancy->initialize($previousTenant);
            } else {
                $tenancy->end();
            }
        }
    }

    protected function findTenantModel(string $tenantModel, string $tenantIdentifier): ?Model
    {
        $tenant = $tenantModel::query()->find($tenantIdentifier);

        if ($tenant) {
            return $tenant;
        }

        $instance = new $tenantModel;

        if (! method_exists($instance, 'getRouteKeyName')) {
            return null;
        }

        return $tenantModel::query()
            ->where($instance->getRouteKeyName(), $tenantIdentifier)
            ->first();
    }

    protected function isDebitNormal(?string $categoryType): bool
    {
        return in_array(strtoupper($categoryType ?: 'ASSET'), ['ASSET', 'EXPENSE'], true);
    }
}
