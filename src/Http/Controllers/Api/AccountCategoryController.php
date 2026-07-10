<?php

namespace ESolution\LaravelAccounting\Http\Controllers\Api;

use ESolution\LaravelAccounting\Http\Controllers\BaseController;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use ESolution\LaravelAccounting\Repositories\AccountCategoryRepository;
use ESolution\LaravelAccounting\Repositories\AccountRepository;
use ESolution\LaravelAccounting\Services\AccountCategoryTreeService;
use ESolution\LaravelAccounting\Http\Resources\AccountCategoryResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class AccountCategoryController extends BaseController
{
    protected $cacheKey = 'acc_account_categories';

    public function index(Request $request, $tenantId = null)
    {
        $this->initializeTenantIfNeeded($tenantId);
        $includeAccounts = $this->shouldIncludeAccounts($request);
        $includeChildren = $this->shouldIncludeChildren($request);
        $rootOnly = filter_var($request->query('root_only', false), FILTER_VALIDATE_BOOLEAN);
        $parentId = $this->normalizeParentId($request->query('parent_id'));

        $cacheKey = 'index_'
            .($includeChildren ? 'tree' : 'flat')
            .'_parent_'.md5((string) ($parentId ?? '__all__'))
            .'_root_'.($rootOnly ? '1' : '0')
            .'_with_'.($includeAccounts ? 'accounts' : 'none');

        $categories = Cache::tags($this->getCacheTags($tenantId))->rememberForever($cacheKey, function () use ($includeAccounts, $includeChildren, $rootOnly, $parentId) {
            $categoryRepository = app(AccountCategoryRepository::class);
            $treeService = app(AccountCategoryTreeService::class);
            $categories = $categoryRepository->allOrdered();

            if ($parentId !== null) {
                $categories = $categories->where('parent_id', $parentId)->values();
            } elseif ($rootOnly || $includeChildren) {
                $categories = $categories->whereNull('parent_id')->values();
            }

            $accounts = $includeAccounts ? app(AccountRepository::class)->allOrdered() : collect();

            if ($includeChildren) {
                $nodes = $categories
                    ->map(fn (AccountCategory $category) => $treeService->buildNode($category, $categories->merge(app(AccountCategoryRepository::class)->allOrdered())->unique('id')->values(), $accounts))
                    ->values();

                return $this->stripMissingRelationsFromTree($nodes, $includeAccounts);
            }

            if ($includeAccounts) {
                $categories = $categoryRepository->attachAccounts($categories, $accounts);
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

    protected function shouldIncludeAccounts(Request $request): bool
    {
        $with = $request->query('with', []);

        if (is_string($with)) {
            return in_array('accounts', array_map('trim', explode(',', $with)), true);
        }

        if (! is_array($with)) {
            return false;
        }

        $flattened = [];
        foreach ($with as $value) {
            if (is_array($value)) {
                foreach ($value as $nested) {
                    $flattened[] = (string) $nested;
                }
                continue;
            }

            foreach (explode(',', (string) $value) as $part) {
                $flattened[] = trim($part);
            }
        }

        return in_array('accounts', $flattened, true);
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

    protected function shouldIncludeChildren(Request $request): bool
    {
        $with = $request->query('with', []);

        if (is_string($with)) {
            return in_array('children', array_map('trim', explode(',', $with)), true);
        }

        if (! is_array($with)) {
            return false;
        }

        $flattened = [];
        foreach ($with as $value) {
            if (is_array($value)) {
                foreach ($value as $nested) {
                    $flattened[] = (string) $nested;
                }
                continue;
            }

            foreach (explode(',', (string) $value) as $part) {
                $flattened[] = trim($part);
            }
        }

        return in_array('children', $flattened, true);
    }

    protected function stripMissingRelationsFromTree($tree, bool $includeAccounts = false)
    {
        return collect($tree)->map(function ($node) use ($includeAccounts) {
            if (is_array($node)) {
                if (! array_key_exists('children', $node)) {
                    $node['children'] = [];
                } else {
                    $node['children'] = $this->stripMissingRelationsFromTree($node['children'])->all();
                }

                if (! $includeAccounts) {
                    unset($node['accounts']);
                }

                return $node;
            }

            return $node;
        });
    }
}
