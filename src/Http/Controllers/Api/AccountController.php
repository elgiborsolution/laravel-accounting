<?php

namespace ESolution\LaravelAccounting\Http\Controllers\Api;

use ESolution\LaravelAccounting\Http\Controllers\BaseController;
use ESolution\LaravelAccounting\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AccountController extends BaseController
{
    protected $cacheKey = 'acc_accounts';

    public function index(Request $request, $tenantId = null)
    {
        $this->initializeTenantIfNeeded($tenantId);

        $search = $request->query('search');
        $cacheKey = 'index_'.($search ? 'search_'.md5($search) : 'all');

        $accounts = Cache::tags($this->getCacheTags($tenantId))->rememberForever($cacheKey, function () use ($search) {
            $data = Account::when($search, function ($query, $search) {
                $query->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            })
                ->orderBy('code')
                ->get();

            $data->load('category');

            return $data;
        });

        return $this->successResponse('Accounts retrieved successfully', $accounts);
    }

    public function store(Request $request, $tenantId = null)
    {
        $this->initializeTenantIfNeeded($tenantId);

        $validated = $request->validate([
            'category_id' => 'required|exists:acc_account_categories,id',
            'code' => 'required|string|max:30|unique:acc_accounts,code',
            'name' => 'required|string|max:200',
            'is_postable' => 'nullable|boolean',
            'status' => 'nullable|boolean',
        ]);

        $account = Account::create($validated);
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

        $account = Cache::tags($this->getCacheTags($tenantId))->rememberForever('show_'.$id, function () use ($id) {
            $acc = Account::findOrFail($id);
            $acc->load(['category']);

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

        $account = Account::findOrFail($id);

        $validated = $request->validate([
            'category_id' => 'nullable|exists:acc_account_categories,id',
            'code' => 'nullable|string|max:30|unique:acc_accounts,code,'.$id,
            'name' => 'nullable|string|max:200',
            'is_postable' => 'nullable|boolean',
            'status' => 'nullable|boolean',
        ]);

        $account->update($validated);
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

        $account = Account::findOrFail($id);

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

        $account = Account::findOrFail($id);
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
}
