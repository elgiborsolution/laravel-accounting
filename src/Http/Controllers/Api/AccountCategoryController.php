<?php

namespace ESolution\LaravelAccounting\Http\Controllers\Api;

use ESolution\LaravelAccounting\Http\Controllers\BaseController;
use ESolution\LaravelAccounting\Http\Resources\AccountCategoryResource;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use ESolution\LaravelAccounting\Repositories\AccountCategoryRepository;
use ESolution\LaravelAccounting\Repositories\AccountRepository;
use ESolution\LaravelAccounting\Services\AccountBalanceService;
use ESolution\LaravelAccounting\Services\AccountCategoryTreeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class AccountCategoryController extends BaseController
{
    protected $cacheKey = 'acc_account_categories';

    public function index(Request $request, $tenantId = null)
    {
        $this->initializeTenantIfNeeded($tenantId);

        $with = $this->normalizeWithParameter($request->query('with'));
        $includeAccounts = in_array('accounts', $with, true);
        $includeChildren = in_array('children', $with, true);
        $includeBalance = in_array('balance', $with, true);
        $rootOnly = filter_var($request->query('root_only', false), FILTER_VALIDATE_BOOLEAN);
        $parentId = $this->normalizeParentId($request->query('parent_id'));
        $tenantFilter = $this->resolveCurrentTenantIdentifier($request);
        $balanceYear = (int) $request->query('year', now()->year);
        $balanceMonth = (int) $request->query('month', now()->month);

        $cacheKey = 'index_'
            .($includeChildren ? 'tree' : 'flat')
            .'_parent_'.md5((string) ($parentId ?? '__all__'))
            .'_root_'.($rootOnly ? '1' : '0')
            .'_with_'.($with ? implode('-', $with) : 'none')
            .'_tenant_'.md5((string) ($tenantFilter ?? '__central__'))
            .($includeBalance ? '_period_'.$balanceYear.'_'.$balanceMonth : '');

        $cacheTags = $this->getCacheTags($tenantId);
        if ($includeBalance) {
            $cacheTags = array_values(array_unique(array_merge(
                $cacheTags,
                ['acc_accounts', 'acc_journals'],
                $tenantId ? ['acc_accounts_tenant_'.$tenantId, 'acc_journals_tenant_'.$tenantId] : []
            )));
        }

        $categories = Cache::tags($cacheTags)->rememberForever($cacheKey, function () use (
            $includeAccounts,
            $includeChildren,
            $includeBalance,
            $rootOnly,
            $parentId,
            $tenantFilter,
            $balanceYear,
            $balanceMonth
        ) {
            $categoryRepository = app(AccountCategoryRepository::class);
            $treeService = app(AccountCategoryTreeService::class);
            $allCategories = $categoryRepository->allOrdered();
            $categories = $allCategories;

            if ($parentId !== null) {
                $categories = $categories->where('parent_id', $parentId)->values();
            } elseif ($rootOnly || $includeChildren) {
                $categories = $categories->whereNull('parent_id')->values();
            }

            $accounts = ($includeAccounts || $includeBalance)
                ? app(AccountRepository::class)->visibleOrdered($tenantFilter)
                : collect();

            $accountBalanceMap = $includeBalance
                ? app(AccountBalanceService::class)->getBalances($accounts->pluck('id')->all(), $balanceYear, $balanceMonth)
                : collect();

            if ($includeBalance) {
                $accounts = $this->attachAccountBalances($accounts, $accountBalanceMap);
            }

            $categoryBalanceMap = $includeBalance
                ? $this->buildCategoryBalanceMap($allCategories, $accounts, $accountBalanceMap)
                : collect();

            if ($includeChildren) {
                $nodes = $categories
                    ->map(fn (AccountCategory $category) => $treeService->buildNode($category, $allCategories, $accounts))
                    ->values();

                if ($includeBalance) {
                    $nodes = $this->attachBalancesToTree($nodes, $categoryBalanceMap);
                }

                return $this->stripMissingRelationsFromTree($nodes, $includeAccounts, $includeBalance);
            }

            if ($includeAccounts) {
                $categories = $categoryRepository->attachAccounts($categories, $accounts);
            }

            if ($includeBalance) {
                $categories = $this->attachCategoryBalances($categories, $categoryBalanceMap);
            }

            return AccountCategoryResource::collection($categories);
        });

        return $this->successResponse('Account categories retrieved successfully', $categories);
    }

    public function store(Request $request, $tenantId = null)
    {
        $this->initializeTenantIfNeeded($tenantId);

        $validated = $request->validate([
            'parent_id' => ['nullable', Rule::exists(AccountCategory::validationTable(), 'id')],
            'type' => 'required|in:ASSET,LIABILITY,EQUITY,REVENUE,EXPENSE,asset,liability,equity,revenue,expense',
            'category_code' => ['required', 'string', 'max:50', Rule::unique(AccountCategory::validationTable(), 'category_code')],
            'category_name' => 'required|string|max:100',
            'report_type' => 'nullable|string|max:50',
            'sequence_no' => 'nullable|integer',
            'status' => 'nullable|boolean',
        ]);

        $validated['type'] = strtoupper($validated['type']);
        $validated['report_type'] = $validated['report_type'] ?? ($validated['type'] === 'ASSET' || $validated['type'] === 'LIABILITY' || $validated['type'] === 'EQUITY' ? 'BS' : 'PL');

        $category = AccountCategory::create($validated);
        $this->clearCache($tenantId);

        return $this->successResponse('Account category created successfully', $category, 201);
    }

    public function show(Request $request, $tenantId = null, $id = null)
    {
        if ($id === null) {
            $id = $tenantId;
            $tenantId = null;
        }
        $this->initializeTenantIfNeeded($tenantId);

        $category = Cache::tags($this->getCacheTags($tenantId))->rememberForever('show_'.$id, function () use ($id) {
            $category = app(AccountCategoryRepository::class)->findById($id);

            if (! $category) {
                abort(404);
            }

            return app(AccountCategoryTreeService::class)->buildNode($category);
        });

        return $this->successResponse('Account category retrieved successfully', $category);
    }

    public function update(Request $request, $tenantId = null, $id = null)
    {
        if ($id === null) {
            $id = $tenantId;
            $tenantId = null;
        }
        $this->initializeTenantIfNeeded($tenantId);

        $category = AccountCategory::findOrFail($id);

        $validated = $request->validate([
            'parent_id' => ['nullable', Rule::exists(AccountCategory::validationTable(), 'id')],
            'type' => 'nullable|in:ASSET,LIABILITY,EQUITY,REVENUE,EXPENSE,asset,liability,equity,revenue,expense',
            'category_code' => ['nullable', 'string', 'max:50', Rule::unique(AccountCategory::validationTable(), 'category_code')->ignore($id)],
            'category_name' => 'nullable|string|max:100',
            'report_type' => 'nullable|string|max:50',
            'sequence_no' => 'nullable|integer',
            'status' => 'nullable|boolean',
        ]);

        if (isset($validated['type'])) {
            $validated['type'] = strtoupper($validated['type']);
        }

        if (! array_key_exists('report_type', $validated)) {
            $validated['report_type'] = in_array($validated['type'] ?? $category->type, ['ASSET', 'LIABILITY', 'EQUITY'], true) ? 'BS' : 'PL';
        }

        $category->update($validated);
        $this->clearCache($tenantId);

        return $this->successResponse('Account category updated successfully', $category);
    }

    public function destroy(Request $request, $tenantId = null, $id = null)
    {
        if ($id === null) {
            $id = $tenantId;
            $tenantId = null;
        }
        $this->initializeTenantIfNeeded($tenantId);

        $category = AccountCategory::findOrFail($id);
        $hasChildren = AccountCategory::where('parent_id', $category->id)->exists();
        $hasAccounts = Account::where('category_id', $category->id)->exists();

        if ($hasChildren || $hasAccounts) {
            return $this->errorResponse(['category' => 'Cannot delete category with descendants or accounts'], 422, 'Validation Error');
        }
        $category->delete();
        $this->clearCache($tenantId);

        return $this->successResponse('Account category deleted successfully');
    }

    public function toggleStatus(Request $request, $tenantId = null, $id = null)
    {
        if ($id === null) {
            $id = $tenantId;
            $tenantId = null;
        }
        $this->initializeTenantIfNeeded($tenantId);

        $category = AccountCategory::findOrFail($id);
        $category->status = ! $category->status;
        $category->save();
        $this->clearCache($tenantId);

        return $this->successResponse('Account category status toggled successfully', $category);
    }

    protected function clearCache($tenantId = null)
    {
        Cache::tags($this->getCacheTags($tenantId))->flush();
        Cache::tags(array_merge(['acc_accounts'], $tenantId ? ['acc_accounts_tenant_'.$tenantId] : []))->flush();
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
        $with = array_values(array_intersect($with, ['accounts', 'children', 'balance']));
        sort($with);

        return $with;
    }

    protected function normalizeParentId($parentId): ?string
    {
        if ($parentId === null) {
            return null;
        }

        $parentId = trim((string) $parentId);

        if ($parentId === '' || strtolower($parentId) === 'null') {
            return null;
        }

        return $parentId;
    }

    protected function stripMissingRelationsFromTree($tree, bool $includeAccounts = false, bool $includeBalance = false)
    {
        return collect($tree)->map(function ($node) use ($includeAccounts, $includeBalance) {
            if (is_array($node)) {
                if (! array_key_exists('children', $node)) {
                    $node['children'] = [];
                } else {
                    $node['children'] = $this->stripMissingRelationsFromTree($node['children'], $includeAccounts, $includeBalance)->all();
                }

                if (! $includeAccounts) {
                    unset($node['accounts']);
                }

                if (! $includeBalance) {
                    unset($node['balance']);
                }

                return $node;
            }

            return $node;
        });
    }

    protected function attachAccountBalances($accounts, $accountBalanceMap)
    {
        return $accounts->map(function (Account $account) use ($accountBalanceMap) {
            $account->setAttribute('balance', (float) data_get($accountBalanceMap->get($account->id), 'ending_balance', 0));

            return $account;
        });
    }

    protected function buildCategoryBalanceMap($categories, $accounts, $accountBalanceMap)
    {
        $computed = [];
        $categoriesByParent = $categories->groupBy(fn (AccountCategory $category) => $category->parent_id ?? '__root__');
        $accountsByCategory = $accounts->groupBy('category_id');

        $resolve = function (string $categoryId) use (&$resolve, &$computed, $categoriesByParent, $accountsByCategory, $accountBalanceMap): float {
            if (array_key_exists($categoryId, $computed)) {
                return $computed[$categoryId];
            }

            $directAccountBalance = $accountsByCategory->get($categoryId, collect())
                ->sum(fn (Account $account) => (float) data_get($accountBalanceMap->get($account->id), 'ending_balance', 0));

            $childCategoryBalance = $categoriesByParent->get($categoryId, collect())
                ->sum(fn (AccountCategory $child) => $resolve($child->id));

            $computed[$categoryId] = round($directAccountBalance + $childCategoryBalance, 2);

            return $computed[$categoryId];
        };

        foreach ($categories as $category) {
            $resolve($category->id);
        }

        return collect($computed);
    }

    protected function attachCategoryBalances($categories, $categoryBalanceMap)
    {
        return $categories->map(function (AccountCategory $category) use ($categoryBalanceMap) {
            $category->setAttribute('balance', (float) $categoryBalanceMap->get($category->id, 0));

            return $category;
        });
    }

    protected function attachBalancesToTree($tree, $categoryBalanceMap)
    {
        return collect($tree)->map(function ($node) use ($categoryBalanceMap) {
            if (! is_array($node)) {
                return $node;
            }

            $node['balance'] = (float) $categoryBalanceMap->get($node['id'], 0);

            if (array_key_exists('accounts', $node)) {
                $node['accounts'] = collect($node['accounts'])->map(function ($account) {
                    if ($account instanceof Account) {
                        return $account;
                    }

                    if (is_array($account)) {
                        $account['balance'] = (float) ($account['balance'] ?? 0);
                    }

                    return $account;
                })->values()->all();
            }

            if (array_key_exists('children', $node)) {
                $node['children'] = $this->attachBalancesToTree($node['children'], $categoryBalanceMap)->all();
            }

            return $node;
        });
    }
}
