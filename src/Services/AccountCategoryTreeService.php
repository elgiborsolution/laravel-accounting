<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Http\Resources\AccountCategoryResource;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use ESolution\LaravelAccounting\Repositories\AccountCategoryRepository;
use ESolution\LaravelAccounting\Repositories\AccountRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountCategoryTreeService
{
    public function __construct(
        protected AccountCategoryRepository $categories,
        protected AccountRepository $accounts
    ) {}

    public function getCategories(): Collection
    {
        return $this->categories->allOrdered();
    }

    public function getTree(?Collection $categories = null, ?Collection $accounts = null): Collection
    {
        $categories = $categories ?? $this->getCategories();
        $accounts = $accounts ?? $this->accounts->allOrdered();

        return $this->categories->buildTree($categories, $accounts);
    }

    public function buildNode(AccountCategory $category, ?Collection $categories = null, ?Collection $accounts = null): array
    {
        return $this->findNodeById($this->getTree($categories, $accounts), $category->id) ?? [];
    }

    public function getDescendants(AccountCategory $category): Collection
    {
        return $this->categories->getDescendants($category);
    }

    public function buildPath(AccountCategory $category): array
    {
        return $this->categories->buildLineage($category)
            ->map(fn (AccountCategory $node) => $node->category_name)
            ->all();
    }

    public function flatten(Collection $tree): Collection
    {
        $flattened = collect();

        foreach ($tree as $node) {
            $flattened->push($node);

            if (! empty($node['children'])) {
                $flattened = $flattened->merge($this->flatten(collect($node['children'])));
            }
        }

        return $flattened->values();
    }

    protected function findNodeById(Collection $tree, $id): ?array
    {
        foreach ($tree as $node) {
            if ($node['id'] === $id) {
                return $node;
            }

            $found = $this->findNodeById(collect($node['children'] ?? []), $id);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Retrieve API-compatible category items without initializing a tenant or
     * returning an HTTP envelope. Explicit tenant lists merge complete charts.
     */
    public function index(array $filters = [], ?array $tenantIds = null): Collection|LengthAwarePaginator
    {
        if (array_key_exists('tenant_ids', $filters)) {
            validator($filters, ['tenant_ids' => ['required', 'array', 'list', 'min:1']])->validate();
            $tenantIds ??= $filters['tenant_ids'];
        }
        if ($tenantIds !== null) {
            $filters['tenant_ids'] = $tenantIds;
        }
        $with = $this->normalizeWithParameter($filters['with'] ?? null);
        $options = [
            'accounts' => in_array('accounts', $with, true),
            'children' => in_array('children', $with, true),
            'balance' => in_array('balance', $with, true),
            'has_accounts' => filter_var($filters['has_accounts'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'search' => $this->normalizeSearchTerm($filters['search'] ?? null),
            'type' => $this->normalizeCategoryType($filters['type'] ?? null),
            'root_only' => filter_var($filters['root_only'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'parent_id' => $this->normalizeParentId($filters['parent_id'] ?? null),
            'year' => (int) (array_key_exists('year', $filters) ? $filters['year'] : now()->year),
            'month' => (int) (array_key_exists('month', $filters) ? $filters['month'] : now()->month),
        ];
        $balances = null;

        if ($tenantIds !== null) {
            $merged = app(ConsolidatedReportService::class)->categoryTree(
                $tenantIds, $options['year'], $options['month'], $options['balance']
            );
            [$categories, $accounts, $balances] = $this->consolidatedRecords($merged['data']);
            if ($options['parent_id'] !== null) {
                $codes = $merged['category_aliases'][$options['parent_id']] ?? [];
                if ($categories->containsStrict('id', $options['parent_id'])) {
                    $codes[] = $options['parent_id'];
                }
                $codes = array_values(array_unique($codes));
                if (count($codes) > 1) {
                    throw ValidationException::withMessages(['parent_id' => ['The parent identifier matches incompatible category codes across tenants.']]);
                }
                $options['parent_id'] = $codes[0] ?? $options['parent_id'];
            }
        } else {
            $categories = $this->categories->allOrdered();
            $related = $options['accounts'] || $options['balance'] || $options['has_accounts'] || $options['search'] !== null;
            $accounts = $related ? $this->accounts->visibleOrdered($this->currentTenantIdentifier()) : collect();
        }

        validator(['type' => $options['type']], ['type' => ['nullable', Rule::in($this->allowedCategoryTypes($categories))]])->validate();
        $items = $this->buildIndexPayload($categories, $accounts, $options, $balances);
        if (! array_key_exists('page', $filters) && ! array_key_exists('per_page', $filters)) {
            return $items;
        }

        $perPage = max(1, (int) (array_key_exists('per_page', $filters) ? $filters['per_page'] : 15));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $paginator = new LengthAwarePaginator($items->forPage($page, $perPage)->values(), $items->count(), $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page',
        ]);

        return $paginator->appends($filters);
    }

    protected function buildIndexPayload(Collection $allCategories, Collection $visibleAccounts, array $options, ?Collection $balances = null): Collection
    {
        if ($options['type'] !== null) {
            $allCategories = $allCategories->filter(fn (AccountCategory $category) => strtolower((string) $category->getRawOriginal('type')) === $options['type'])->values();
        }
        $matchedAccounts = $this->filterAccountsBySearch($visibleAccounts, $options['search']);
        if ($options['has_accounts'] || $options['search'] !== null) {
            $allCategories = $this->filterCategoriesForSearch($allCategories, $matchedAccounts, $options['search'], $options['has_accounts']);
        }

        $balanceAccounts = $visibleAccounts->filter(fn (Account $account) => $allCategories->containsStrict('id', $account->category_id))->values();
        $relationAccounts = ($options['search'] !== null ? $matchedAccounts : $visibleAccounts)
            ->filter(fn (Account $account) => $allCategories->containsStrict('id', $account->category_id))->values();
        $categories = $allCategories;
        if ($options['parent_id'] !== null) {
            $categories = $categories->whereStrict('parent_id', $options['parent_id'])->values();
        } elseif ($options['root_only'] || $options['children']) {
            $categories = $categories->whereNull('parent_id')->values();
        }

        $balanceMap = $options['balance'] ? ($balances ?? app(AccountBalanceService::class)->getBalances(
            $balanceAccounts->pluck('id')->all(), $options['year'], $options['month']
        )) : collect();
        if ($options['balance']) {
            $balanceAccounts = $this->attachAccountBalances($balanceAccounts, $balanceMap);
            if ($options['accounts']) {
                $relationAccounts = $this->attachAccountBalances($relationAccounts, $balanceMap);
            }
        }
        $categoryBalances = $options['balance']
            ? $this->buildCategoryBalanceMap($allCategories, $balanceAccounts, $balanceMap) : collect();

        if ($options['children']) {
            $nodes = $categories->map(fn (AccountCategory $category) => $this->buildNode(
                $category, $allCategories, $options['accounts'] ? $relationAccounts : $balanceAccounts
            ))->values();
            if ($options['balance']) {
                $nodes = $this->attachBalancesToTree($nodes, $categoryBalances);
            }

            return $this->stripMissingRelationsFromTree($nodes, $options['accounts'], $options['balance'])->values();
        }

        if ($options['accounts']) {
            $categories = $this->categories->attachAccounts($categories, $relationAccounts);
        }
        if ($options['balance']) {
            $categories = $this->attachCategoryBalances($categories, $categoryBalances);
        }

        return collect(AccountCategoryResource::collection($categories)->resolve(request()));
    }

    protected function consolidatedRecords(array $nodes): array
    {
        $categories = collect();
        $accounts = collect();
        $balances = collect();
        $walk = function (array $nodes) use (&$walk, $categories, $accounts, $balances): void {
            foreach ($nodes as $node) {
                $category = new AccountCategory;
                $category->setRawAttributes([
                    'id' => $node['category_code'], 'parent_id' => $node['parent_code'],
                    'category_code' => $node['category_code'], 'type' => strtolower($node['type']),
                    'category_name' => $node['category_name'], 'report_type' => $node['report_type'],
                    'sequence_no' => $node['sequence_no'], 'status' => $node['status'],
                ], true);
                $categories->push($category);
                foreach ($node['accounts'] as $data) {
                    $account = new Account;
                    $account->setRawAttributes([
                        'id' => $data['code'], 'category_id' => $node['category_code'],
                        'code' => $data['code'], 'name' => $data['name'], 'description' => $data['description'],
                        'is_postable' => $data['is_postable'], 'status' => $data['status'],
                    ], true);
                    $accounts->push($account);
                    $balances->put($data['code'], ['ending_balance' => $data['balance']]);
                }
                $walk($node['children']);
            }
        };
        $walk($nodes);

        return [
            $categories->sortBy(fn (AccountCategory $category) => [$category->sequence_no, $category->category_name, $category->category_code])->values(),
            $accounts->sort(fn (Account $a, Account $b) => strcmp($a->code, $b->code))->values(),
            $balances,
        ];
    }

    protected function currentTenantIdentifier(): ?string
    {
        if (function_exists('tenancy')) {
            try {
                if (tenancy()->initialized && isset(tenancy()->tenant) && tenancy()->tenant) {
                    return (string) tenancy()->tenant->id;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $header = request()->header('X-Tenant');
        if ($header !== null && $header !== '') {
            return (string) $header;
        }
        $route = request()->route('tenantId');

        return $route !== null && $route !== '' ? (string) $route : null;
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

    protected function allowedCategoryTypes(Collection $categories): array
    {
        return $categories->pluck('type')->filter(fn ($type) => $type !== null && $type !== '')
            ->map(fn ($type) => strtolower((string) $type))->unique()->values()->all();
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
                        $current = $current->parent_id !== null ? $categoriesById->get($current->parent_id) : null;
                    }
                }
            }
        }

        foreach ($matchedAccounts->pluck('category_id')->filter(fn ($id) => $id !== null && $id !== '')->uniqueStrict() as $categoryId) {
            $current = $categoriesById->get($categoryId);

            while ($current) {
                $includedIds[$current->id] = true;
                $current = $current->parent_id !== null ? $categoriesById->get($current->parent_id) : null;
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
