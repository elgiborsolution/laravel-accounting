<?php

namespace ESolution\LaravelAccounting\Repositories;

use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use Illuminate\Support\Collection;

class AccountRepository
{
    public function allOrdered(): Collection
    {
        return Account::query()->orderBy('code')->get();
    }

    public function findById(string $id): ?Account
    {
        return Account::query()->find($id);
    }

    public function findByCode(string $code): ?Account
    {
        return Account::query()->where('code', $code)->first();
    }

    public function findManyByIds(Collection|array $ids): Collection
    {
        return Account::query()
            ->whereIn('id', collect($ids)->values())
            ->get()
            ->keyBy('id');
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
