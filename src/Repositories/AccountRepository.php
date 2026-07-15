<?php

namespace ESolution\LaravelAccounting\Repositories;

use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AccountRepository
{
    public function allOrdered(): Collection
    {
        return Account::query()->orderBy('code')->get();
    }

    public function visibleOrdered(?string $tenantId = null): Collection
    {
        return $this->visibleQuery($tenantId)->orderBy('code')->get();
    }

    public function visibleOrderedByCategory(?string $tenantId = null, ?string $categoryId = null): Collection
    {
        return $this->visibleQuery($tenantId, $categoryId)->orderBy('code')->get();
    }

    public function findById(string $id): ?Account
    {
        return Account::query()->find($id);
    }

    public function findByIdVisible(string $id, ?string $tenantId = null): ?Account
    {
        return $this->visibleQuery($tenantId)->whereKey($id)->first();
    }

    public function findByCode(string $code): ?Account
    {
        return Account::query()->where('code', $code)->first();
    }

    public function findByCodeVisible(string $code, ?string $tenantId = null): ?Account
    {
        return $this->visibleQuery($tenantId)->where('code', $code)->first();
    }

    public function findManyByIds(Collection|array $ids): Collection
    {
        return Account::query()
            ->whereIn('id', collect($ids)->values())
            ->get()
            ->keyBy('id');
    }

    public function findManyByIdsVisible(Collection|array $ids, ?string $tenantId = null): Collection
    {
        return $this->visibleQuery($tenantId)
            ->whereIn('id', collect($ids)->values())
            ->get()
            ->keyBy('id');
    }

    public function visibleQuery(?string $tenantId = null, ?string $categoryId = null): Builder
    {
        $query = Account::query();

        if ($tenantId === null || $tenantId === '') {
            $query->whereNull('tenant_id');
        } else {
            $query->where(function (Builder $builder) use ($tenantId) {
                $builder->whereNull('tenant_id')
                    ->orWhere('tenant_id', $tenantId);
            });
        }

        if ($categoryId !== null && $categoryId !== '') {
            $query->where('category_id', $categoryId);
        }

        return $query;
    }

    public function attachCategories(Collection $accounts, ?Collection $categories = null): Collection
    {
        $categoriesById = ($categories ?? AccountCategory::query()->get())->keyBy('id');

        return $accounts->map(function (Account $account) use ($categoriesById) {
            $category = $categoriesById->get($account->category_id);

            if ($category) {
                $account->setRelation('category', $category);
            }

            return $account;
        });
    }

    public function attachMappings(Collection $accounts, Collection $mappings): Collection
    {
        $mappingsByAccount = $mappings->groupBy('account_id');

        return $accounts->map(function (Account $account) use ($mappingsByAccount) {
            $account->setRelation('mappings', $mappingsByAccount->get($account->id, collect())->values());

            return $account;
        });
    }
}
