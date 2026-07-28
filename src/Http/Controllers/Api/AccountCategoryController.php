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
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class AccountCategoryController extends BaseController
{
    protected $cacheKey = 'acc_account_categories';

    public function index(Request $request, $tenantId = null)
    {
        $this->initializeTenantIfNeeded($tenantId);
        $normalizedType = $this->normalizeCategoryType($request->query('type'));
        $validated = validator(
            ['type' => $normalizedType],
            ['type' => ['nullable', Rule::in($this->allowedCategoryTypes())]]
        )->validate();

        if ($request->has('page') || $request->has('per_page')) {
            return $this->paginatedIndexResponse($request, $tenantId, $validated);
        }

        $with = $this->normalizeWithParameter($request->query('with'));
        $includeAccounts = in_array('accounts', $with, true);
        $includeChildren = in_array('children', $with, true);
        $includeBalance = in_array('balance', $with, true);
        $hasAccounts = filter_var($request->query('has_accounts', false), FILTER_VALIDATE_BOOLEAN);
        $search = $this->normalizeSearchTerm($request->query('search'));
        $type = $this->normalizeCategoryType($validated['type'] ?? null);
        $rootOnly = filter_var($request->query('root_only', false), FILTER_VALIDATE_BOOLEAN);
        $parentId = $this->normalizeParentId($request->query('parent_id'));
        $tenantFilter = $this->resolveCurrentTenantIdentifier($request);
        $balanceYear = (int) $request->query('year', now()->year);
        $balanceMonth = (int) $request->query('month', now()->month);

        $cacheKey = 'index_'
            .($includeChildren ? 'tree' : 'flat')
            .'_parent_'.md5((string) ($parentId ?? '__all__'))
            .'_root_'.($rootOnly ? '1' : '0')
            .'_has_accounts_'.($hasAccounts ? '1' : '0')
            .($search ? '_search_'.md5($search) : '')
            .'_type_'.md5((string) ($type ?? '__all__'))
            .'_with_'.($with ? implode('-', $with) : 'none')
            .'_tenant_'.md5((string) ($tenantFilter ?? '__central__'))
            .($includeBalance ? '_period_'.$balanceYear.'_'.$balanceMonth : '');

        $cacheTags = $this->getCacheTags($tenantId);
        if ($includeBalance || $hasAccounts || $search !== null) {
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
            $hasAccounts,
            $search,
            $type,
            $rootOnly,
            $parentId,
            $tenantFilter,
            $balanceYear,
            $balanceMonth
        ) {
            $categoryRepository = app(AccountCategoryRepository::class);
            $treeService = app(AccountCategoryTreeService::class);
            $allCategories = $categoryRepository->allOrdered();
            if ($type !== null) {
                $allCategories = $allCategories->filter(fn (AccountCategory $category) => strtolower((string) $category->getRawOriginal('type')) === $type)->values();
            }
            $visibleAccounts = ($includeAccounts || $includeBalance || $hasAccounts || $search !== null)
                ? app(AccountRepository::class)->visibleOrdered($tenantFilter)
                : collect();
            $matchedAccounts = $this->filterAccountsBySearch($visibleAccounts, $search);

            if ($hasAccounts || $search !== null) {
                $allCategories = $this->filterCategoriesForSearch($allCategories, $matchedAccounts, $search, $hasAccounts);
            }

            $balanceAccounts = $visibleAccounts
                ->filter(fn (Account $account) => $allCategories->contains('id', $account->category_id))
                ->values();
            $relationAccounts = ($search !== null ? $matchedAccounts : $visibleAccounts)
                ->filter(fn (Account $account) => $allCategories->contains('id', $account->category_id))
                ->values();

            $categories = $allCategories;

            if ($parentId !== null) {
                $categories = $categories->where('parent_id', $parentId)->values();
            } elseif ($rootOnly || $includeChildren) {
                $categories = $categories->whereNull('parent_id')->values();
            }

            $accountBalanceMap = $includeBalance
                ? app(AccountBalanceService::class)->getBalances($balanceAccounts->pluck('id')->all(), $balanceYear, $balanceMonth)
                : collect();

            if ($includeBalance) {
                $balanceAccounts = $this->attachAccountBalances($balanceAccounts, $accountBalanceMap);
                if ($includeAccounts) {
                    $relationAccounts = $this->attachAccountBalances($relationAccounts, $accountBalanceMap);
                }
            }

            $categoryBalanceMap = $includeBalance
                ? $this->buildCategoryBalanceMap($allCategories, $balanceAccounts, $accountBalanceMap)
                : collect();

            if ($includeChildren) {
                $nodes = $categories
                    ->map(fn (AccountCategory $category) => $treeService->buildNode($category, $allCategories, $includeAccounts ? $relationAccounts : $balanceAccounts))
                    ->values();

                if ($includeBalance) {
                    $nodes = $this->attachBalancesToTree($nodes, $categoryBalanceMap);
                }

                return $this->stripMissingRelationsFromTree($nodes, $includeAccounts, $includeBalance);
            }

            if ($includeAccounts) {
                $categories = $categoryRepository->attachAccounts($categories, $relationAccounts);
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

    protected function paginatedIndexResponse(Request $request, $tenantId = null, array $validated = []): LengthAwarePaginator
    {
        $items = $this->buildIndexPayload($request, $tenantId, $validated);
        $perPage = max(1, (int) $request->query('per_page', 15));
        $currentPage = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator(
            $items->forPage($currentPage, $perPage)->values(),
            $items->count(),
            $perPage,
            $currentPage,
            [
                'path' => $request->url(),
                'pageName' => 'page',
            ]
        );

        $paginator->appends($request->query());

        return $paginator;
    }

    protected function buildIndexPayload(Request $request, $tenantId = null, array $validated = []): Collection
    {
        $with = $this->normalizeWithParameter($request->query('with'));
        $includeAccounts = in_array('accounts', $with, true);
        $includeChildren = in_array('children', $with, true);
        $includeBalance = in_array('balance', $with, true);
        $hasAccounts = filter_var($request->query('has_accounts', false), FILTER_VALIDATE_BOOLEAN);
        $search = $this->normalizeSearchTerm($request->query('search'));
        $type = $this->normalizeCategoryType($validated['type'] ?? $request->query('type'));
        $rootOnly = filter_var($request->query('root_only', false), FILTER_VALIDATE_BOOLEAN);
        $parentId = $this->normalizeParentId($request->query('parent_id'));
        $tenantFilter = $this->resolveCurrentTenantIdentifier($request);
        $balanceYear = (int) $request->query('year', now()->year);
        $balanceMonth = (int) $request->query('month', now()->month);

        $cacheKey = 'index_payload_'
            .($includeChildren ? 'tree' : 'flat')
            .'_parent_'.md5((string) ($parentId ?? '__all__'))
            .'_root_'.($rootOnly ? '1' : '0')
            .'_has_accounts_'.($hasAccounts ? '1' : '0')
            .($search ? '_search_'.md5($search) : '')
            .'_type_'.md5((string) ($type ?? '__all__'))
            .'_with_'.($with ? implode('-', $with) : 'none')
            .'_tenant_'.md5((string) ($tenantFilter ?? '__central__'))
            .($includeBalance ? '_period_'.$balanceYear.'_'.$balanceMonth : '');

        $cacheTags = $this->getCacheTags($tenantId);
        if ($includeBalance || $hasAccounts || $search !== null) {
            $cacheTags = array_values(array_unique(array_merge(
                $cacheTags,
                ['acc_accounts', 'acc_journals'],
                $tenantId ? ['acc_accounts_tenant_'.$tenantId, 'acc_journals_tenant_'.$tenantId] : []
            )));
        }

        return Cache::tags($cacheTags)->rememberForever($cacheKey, function () use (
            $request,
            $includeAccounts,
            $includeChildren,
            $includeBalance,
            $hasAccounts,
            $search,
            $type,
            $rootOnly,
            $parentId,
            $tenantFilter,
            $balanceYear,
            $balanceMonth
        ) {
            $categoryRepository = app(AccountCategoryRepository::class);
            $treeService = app(AccountCategoryTreeService::class);
            $allCategories = $categoryRepository->allOrdered();
            if ($type !== null) {
                $allCategories = $allCategories->filter(fn (AccountCategory $category) => strtolower((string) $category->getRawOriginal('type')) === $type)->values();
            }
            $visibleAccounts = ($includeAccounts || $includeBalance || $hasAccounts || $search !== null)
                ? app(AccountRepository::class)->visibleOrdered($tenantFilter)
                : collect();
            $matchedAccounts = $this->filterAccountsBySearch($visibleAccounts, $search);

            if ($hasAccounts || $search !== null) {
                $allCategories = $this->filterCategoriesForSearch($allCategories, $matchedAccounts, $search, $hasAccounts);
            }

            $balanceAccounts = $visibleAccounts
                ->filter(fn (Account $account) => $allCategories->contains('id', $account->category_id))
                ->values();
            $relationAccounts = ($search !== null ? $matchedAccounts : $visibleAccounts)
                ->filter(fn (Account $account) => $allCategories->contains('id', $account->category_id))
                ->values();

            $categories = $allCategories;

            if ($parentId !== null) {
                $categories = $categories->where('parent_id', $parentId)->values();
            } elseif ($rootOnly || $includeChildren) {
                $categories = $categories->whereNull('parent_id')->values();
            }

            $accountBalanceMap = $includeBalance
                ? app(AccountBalanceService::class)->getBalances($balanceAccounts->pluck('id')->all(), $balanceYear, $balanceMonth)
                : collect();

            if ($includeBalance) {
                $balanceAccounts = $this->attachAccountBalances($balanceAccounts, $accountBalanceMap);
                if ($includeAccounts) {
                    $relationAccounts = $this->attachAccountBalances($relationAccounts, $accountBalanceMap);
                }
            }

            $categoryBalanceMap = $includeBalance
                ? $this->buildCategoryBalanceMap($allCategories, $balanceAccounts, $accountBalanceMap)
                : collect();

            if ($includeChildren) {
                $nodes = $categories
                    ->map(fn (AccountCategory $category) => $treeService->buildNode($category, $allCategories, $includeAccounts ? $relationAccounts : $balanceAccounts))
                    ->values();

                if ($includeBalance) {
                    $nodes = $this->attachBalancesToTree($nodes, $categoryBalanceMap);
                }

                return $this->stripMissingRelationsFromTree($nodes, $includeAccounts, $includeBalance)->values();
            }

            if ($includeAccounts) {
                $categories = $categoryRepository->attachAccounts($categories, $relationAccounts);
            }

            if ($includeBalance) {
                $categories = $this->attachCategoryBalances($categories, $categoryBalanceMap);
            }

            return collect(AccountCategoryResource::collection($categories)->resolve($request));
        });
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

    protected function allowedCategoryTypes(): array
    {
        return AccountCategory::query()
            ->select('type')
            ->distinct()
            ->pluck('type')
            ->filter(fn ($type) => $type !== null && $type !== '')
            ->map(fn ($type) => strtolower((string) $type))
            ->values()
            ->all();
    }

    protected function normalizeCategoryType($type): ?string
    {
        if ($type === null) {
            return null;
        }

        $type = strtolower(trim((string) $type));

        return $type === '' ? null : $type;
    }

    protected function filterCategoriesForSearch(Collection $categories, Collection $matchedAccounts, ?string $search, bool $hasAccounts): Collection
    {
        if ($hasAccounts && $matchedAccounts->isEmpty()) {
            return collect();
        }

        $categoriesById = $categories->keyBy('id');
        $includedIds = [];

        if ($search !== null && ! $hasAccounts) {
            foreach ($categories as $category) {
                if ($this->matchesSearch($category->category_code, $search) || $this->matchesSearch($category->category_name, $search)) {
                    $current = $category;

                    while ($current) {
                        $includedIds[$current->id] = true;
                        $current = $current->parent_id ? $categoriesById->get($current->parent_id) : null;
                    }
                }
            }
        }

        foreach ($matchedAccounts->pluck('category_id')->filter()->unique() as $categoryId) {
            $current = $categoriesById->get($categoryId);

            while ($current) {
                $includedIds[$current->id] = true;
                $current = $current->parent_id ? $categoriesById->get($current->parent_id) : null;
            }
        }

        return $categories
            ->filter(fn (AccountCategory $category) => isset($includedIds[$category->id]))
            ->values();
    }

    protected function filterAccountsBySearch(Collection $accounts, ?string $search): Collection
    {
        if ($search === null) {
            return $accounts->values();
        }

        return $accounts
            ->filter(fn (Account $account) => $this->matchesSearch($account->code, $search) || $this->matchesSearch($account->name, $search))
            ->values();
    }

    protected function normalizeSearchTerm($search): ?string
    {
        if ($search === null) {
            return null;
        }

        $search = trim((string) $search);

        return $search === '' ? null : $search;
    }

    protected function matchesSearch(?string $value, string $search): bool
    {
        if ($value === null) {
            return false;
        }

        return str_contains(strtolower($value), strtolower($search));
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
