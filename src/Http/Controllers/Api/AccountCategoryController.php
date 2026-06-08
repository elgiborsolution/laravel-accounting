<?php

namespace ESolution\LaravelAccounting\Http\Controllers\Api;

use ESolution\LaravelAccounting\Http\Controllers\BaseController;
use ESolution\LaravelAccounting\Models\AccountCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AccountCategoryController extends BaseController
{
    protected $cacheKey = 'acc_account_categories';

    public function index(Request $request, $tenantId = null)
    {
        $this->initializeTenantIfNeeded($tenantId);

        $categories = Cache::tags($this->getCacheTags($tenantId))->rememberForever('index_all', function () {
            return AccountCategory::orderBy('sequence_no')->get();
        });

        return $this->successResponse('Account categories retrieved successfully', $categories);
    }

    public function store(Request $request, $tenantId = null)
    {
        $this->initializeTenantIfNeeded($tenantId);

        $validated = $request->validate([
            'type' => 'required|in:asset,liability,equity,revenue,expense',
            'category_code' => 'required|string|max:50|unique:acc_account_categories,category_code',
            'category_name' => 'required|string|max:100',
            'report_type' => 'required|string|max:50',
            'sequence_no' => 'nullable|integer',
            'status' => 'nullable|boolean',
        ]);

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
            return AccountCategory::findOrFail($id);
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
            'type' => 'nullable|in:asset,liability,equity,revenue,expense',
            'category_code' => 'nullable|string|max:50|unique:acc_account_categories,category_code,'.$id,
            'category_name' => 'nullable|string|max:100',
            'report_type' => 'nullable|string|max:50',
            'sequence_no' => 'nullable|integer',
            'status' => 'nullable|boolean',
        ]);

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
    }
}
