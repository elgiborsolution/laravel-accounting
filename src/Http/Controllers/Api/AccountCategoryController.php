<?php

namespace ESolution\LaravelAccounting\Http\Controllers\Api;

use ESolution\LaravelAccounting\Http\Controllers\BaseController;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use ESolution\LaravelAccounting\Repositories\AccountCategoryRepository;
use ESolution\LaravelAccounting\Repositories\AccountRepository;
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
        $treeMode = filter_var($request->query('tree', false), FILTER_VALIDATE_BOOLEAN);

        $categories = Cache::tags($this->getCacheTags($tenantId))->rememberForever('index_'.($treeMode ? 'tree' : 'flat'), function () use ($treeMode) {
            $categoryRepository = app(AccountCategoryRepository::class);
            $accountRepository = app(AccountRepository::class);
            $categories = $categoryRepository->allOrdered();

            if ($treeMode) {
                return app(AccountCategoryTreeService::class)->getTree($categories);
            }

            return $categoryRepository->attachParentAndChildren(
                $categoryRepository->attachAccounts($categories, $accountRepository->allOrdered())
            );
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
}
