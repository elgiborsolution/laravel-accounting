<?php

namespace ESolution\LaravelAccounting\Http\Controllers\Api;

use ESolution\LaravelAccounting\Http\Controllers\BaseController;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use ESolution\LaravelAccounting\Services\AccountBalanceService;
use ESolution\LaravelAccounting\Services\AccountOpeningBalanceService;
use ESolution\LaravelAccounting\Repositories\AccountCategoryRepository;
use ESolution\LaravelAccounting\Repositories\AccountRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class AccountController extends BaseController
{
    protected $cacheKey = 'acc_accounts';

    public function index(Request $request, $tenantId = null)
    {
        $this->initializeTenantIfNeeded($tenantId);

        $validated = $request->validate([
            'category_id' => ['nullable', Rule::exists(AccountCategory::validationTable(), 'id')],
        ]);
        $tenantFilter = $this->resolveCurrentTenantIdentifier($request);
        $search = $request->query('search');
        $categoryFilter = $validated['category_id'] ?? null;
        $with = $this->normalizeWithParameter($request->query('with'));
        $includeCategory = in_array('category', $with, true);
        $includeTreeCategory = in_array('tree_category', $with, true);
        $includeBalance = in_array('balance', $with, true);
        $balanceYear = (int) $request->query('year', now()->year);
        $balanceMonth = (int) $request->query('month', now()->month);
        $cacheKey = 'index_'
            .'tenant_'.md5((string) ($tenantFilter ?? '__central__'))
            .'_category_'.md5((string) ($categoryFilter ?? '__all__'))
            .($search ? 'search_'.md5($search) : 'all')
            .'_with_'.($with ? implode('-', $with) : 'none')
            .($includeBalance ? '_period_'.$balanceYear.'_'.$balanceMonth : '');

        $cacheTags = $this->getCacheTags($tenantId);
        if ($includeBalance) {
            $cacheTags = array_values(array_unique(array_merge(
                $cacheTags,
                ['acc_account_categories', 'acc_journals'],
                $tenantId ? ['acc_account_categories_tenant_'.$tenantId, 'acc_journals_tenant_'.$tenantId] : []
            )));
        }

        $accounts = Cache::tags($cacheTags)->rememberForever($cacheKey, function () use ($search, $categoryFilter, $includeCategory, $includeTreeCategory, $includeBalance, $balanceYear, $balanceMonth, $tenantFilter) {
            $repository = app(AccountRepository::class);
            $query = $repository->visibleQuery($tenantFilter, $categoryFilter);

            if ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                });
            }

            $data = $query->orderBy('code')->get();

            if ($includeCategory) {
                $data = $repository->attachCategories($data);
            }

            if ($includeTreeCategory) {
                $data = $this->attachTreeCategories($data);
            }

            if ($includeBalance) {
                $data = $this->attachBalances($data, $balanceYear, $balanceMonth);
            }

            return $data;
        });

        return $this->successResponse('Accounts retrieved successfully', $accounts);
    }

    public function store(Request $request, $tenantId = null)
    {
        $this->initializeTenantIfNeeded($tenantId);

        $validated = $request->validate([
            'category_id' => ['required', Rule::exists(AccountCategory::validationTable(), 'id')],
            'tenant_id' => ['nullable', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:30', Rule::unique(Account::validationTable(), 'code')],
            'name' => 'required|string|max:200',
            'description' => 'nullable|string',
            'opening_balance' => 'nullable|numeric|min:0',
            'opening_balance_date' => 'nullable|date|required_with:opening_balance',
            'is_postable' => 'nullable|boolean',
            'status' => 'nullable|boolean',
        ]);

        $account = app(AccountOpeningBalanceService::class)->createAccount($validated);
        $this->clearCache($tenantId);

        return $this->successResponse('Account created successfully', $account, 201);
    }

    public function show(Request $request, $tenantId = null, $id = null)
    {
        if ($id === null) {
            $id = $tenantId;
            $tenantId = null;
        }
        $this->initializeTenantIfNeeded($tenantId);

        $tenantFilter = $this->resolveCurrentTenantIdentifier($request);
        $balanceYear = (int) $request->query('year', now()->year);
        $balanceMonth = (int) $request->query('month', now()->month);
        $cacheKey = 'show_'.$id.'_tenant_'.md5((string) ($tenantFilter ?? '__central__')).'_period_'.$balanceYear.'_'.$balanceMonth;

        $cacheTags = $this->getCacheTags($tenantId);
        $cacheTags = array_values(array_unique(array_merge(
            $cacheTags,
            ['acc_account_categories', 'acc_journals'],
            $tenantId ? ['acc_account_categories_tenant_'.$tenantId, 'acc_journals_tenant_'.$tenantId] : []
        )));

        $account = Cache::tags($cacheTags)->rememberForever($cacheKey, function () use ($id, $balanceYear, $balanceMonth, $tenantFilter) {
            $acc = app(AccountRepository::class)->findByIdVisible($id, $tenantFilter);

            if (! $acc) {
                abort(404);
            }

            $acc = app(AccountRepository::class)->attachCategories(collect([$acc]))->first();
            $balance = app(AccountBalanceService::class)->getBalances([$acc->id], $balanceYear, $balanceMonth)->get($acc->id);

            if ($balance) {
                $acc->setRelation('balance', collect($balance));
            }

            return $acc;
        });

        return $this->successResponse('Account retrieved successfully', $account);
    }

    public function update(Request $request, $tenantId = null, $id = null)
    {
        if ($id === null) {
            $id = $tenantId;
            $tenantId = null;
        }
        $this->initializeTenantIfNeeded($tenantId);

        $account = app(AccountRepository::class)->findByIdVisible($id, $this->resolveCurrentTenantIdentifier($request));

        if (! $account) {
            abort(404);
        }

        $validated = $request->validate([
            'category_id' => ['nullable', Rule::exists(AccountCategory::validationTable(), 'id')],
            'tenant_id' => ['nullable', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:30', Rule::unique(Account::validationTable(), 'code')->ignore($id)],
            'name' => 'nullable|string|max:200',
            'description' => 'nullable|string',
            'opening_balance' => 'nullable|numeric|min:0',
            'opening_balance_date' => 'nullable|date|required_with:opening_balance',
            'is_postable' => 'nullable|boolean',
            'status' => 'nullable|boolean',
        ]);

        $account = app(AccountOpeningBalanceService::class)->updateAccount($account, $validated);
        $this->clearCache($tenantId);

        return $this->successResponse('Account updated successfully', $account);
    }

    public function destroy(Request $request, $tenantId = null, $id = null)
    {
        if ($id === null) {
            $id = $tenantId;
            $tenantId = null;
        }
        $this->initializeTenantIfNeeded($tenantId);

        $account = app(AccountRepository::class)->findByIdVisible($id, $this->resolveCurrentTenantIdentifier($request));

        if (! $account) {
            abort(404);
        }

        $account->delete();
        $this->clearCache($tenantId);

        return $this->successResponse('Account deleted successfully');
    }

    public function toggleStatus(Request $request, $tenantId = null, $id = null)
    {
        if ($id === null) {
            $id = $tenantId;
            $tenantId = null;
        }
        $this->initializeTenantIfNeeded($tenantId);

        $account = app(AccountRepository::class)->findByIdVisible($id, $this->resolveCurrentTenantIdentifier($request));

        if (! $account) {
            abort(404);
        }
        $account->status = ! $account->status;
        $account->save();
        $this->clearCache($tenantId);

        return $this->successResponse('Account status toggled successfully', $account);
    }

    protected function clearCache($tenantId = null)
    {
        Cache::tags($this->getCacheTags($tenantId))->flush();
        Cache::tags(array_merge(['acc_account_categories'], $tenantId ? ['acc_account_categories_tenant_'.$tenantId] : []))->flush();
    }

    protected function normalizeWithParameter(mixed $with): array
    {
        if (is_array($with)) {
            $values = [];

            foreach ($with as $value) {
                if (is_array($value)) {
                    foreach ($value as $nested) {
                        $values[] = trim((string) $nested);
                    }

                    continue;
                }

                foreach (explode(',', (string) $value) as $part) {
                    $values[] = trim($part);
                }
            }

            $with = $values;
        } elseif (is_string($with)) {
            $with = array_map('trim', explode(',', $with));
        } else {
            return [];
        }

        $with = array_values(array_filter($with, fn ($item) => $item !== ''));
        $with = array_values(array_intersect($with, ['category', 'tree_category', 'balance']));
        sort($with);

        return $with;
    }

    protected function attachTreeCategories($accounts)
    {
        $categoryRepository = app(AccountCategoryRepository::class);
        $categoriesById = $categoryRepository->allOrdered()->keyBy('id');

        return $accounts->map(function (Account $account) use ($categoriesById, $categoryRepository) {
            $category = $categoriesById->get($account->category_id);

            if (! $category) {
                return $account;
            }

            $category->setRelation('tree_category', $categoryRepository->buildLineage($category));
            $account->setRelation('tree_category', $category->getRelation('tree_category'));

            return $account;
        });
    }

    protected function attachBalances($accounts, int $year, int $month)
    {
        $balanceMap = app(AccountBalanceService::class)->getBalances(
            $accounts->pluck('id')->all(),
            $year,
            $month
        );

        return $accounts->map(function (Account $account) use ($balanceMap) {
            $balance = $balanceMap->get($account->id);

            if ($balance) {
                $account->setRelation('balance', collect($balance));
            }

            return $account;
        });
    }
}
