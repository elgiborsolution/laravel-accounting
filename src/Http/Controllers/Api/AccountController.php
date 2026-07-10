<?php

namespace ESolution\LaravelAccounting\Http\Controllers\Api;

use ESolution\LaravelAccounting\Http\Controllers\BaseController;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
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

        $search = $request->query('search');
        $with = $this->normalizeWithParameter($request->query('with'));
        $includeCategory = $with === 'category';
        $includeTreeCategory = $with === 'tree_category';
        $cacheKey = 'index_'
            .($search ? 'search_'.md5($search) : 'all')
            .'_with_'.($with ?? 'none');

        $accounts = Cache::tags($this->getCacheTags($tenantId))->rememberForever($cacheKey, function () use ($search, $includeCategory, $includeTreeCategory) {
            $data = Account::when($search, function ($query, $search) {
                $query->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
                })
                ->orderBy('code')
                ->get();

            if ($includeTreeCategory) {
                return $this->attachTreeCategories($data);
            }

            if ($includeCategory) {
                return app(AccountRepository::class)->attachCategories($data);
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
            'code' => ['required', 'string', 'max:30', Rule::unique(Account::validationTable(), 'code')],
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

            return app(AccountRepository::class)->attachCategories(collect([$acc]))->first();
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
            'category_id' => ['nullable', Rule::exists(AccountCategory::validationTable(), 'id')],
            'code' => ['nullable', 'string', 'max:30', Rule::unique(Account::validationTable(), 'code')->ignore($id)],
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

    protected function normalizeWithParameter(mixed $with): ?string
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
            return null;
        }

        $with = array_values(array_filter($with, fn ($item) => $item !== ''));

        if (count($with) !== 1) {
            return null;
        }

        return in_array($with[0], ['category', 'tree_category'], true) ? $with[0] : null;
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
}
