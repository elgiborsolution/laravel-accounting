<?php

namespace ESolution\LaravelAccounting\Repositories;

use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use Illuminate\Support\Collection;

class AccountCategoryRepository
{
    public function allOrdered(): Collection
    {
        return AccountCategory::query()
            ->orderBy('sequence_no')
            ->orderBy('category_name')
            ->get();
    }

    public function findById(string $id): ?AccountCategory
    {
        return AccountCategory::query()->find($id);
    }

    public function findByCode(string $code): ?AccountCategory
    {
        return AccountCategory::query()
            ->where('category_code', $code)
            ->first();
    }

    public function getDescendants(AccountCategory $category): Collection
    {
        $categories = $this->allOrdered()->keyBy('id');
        $descendants = collect();

        $walk = function (string $parentId) use (&$walk, $categories, &$descendants): void {
            foreach ($categories->where('parent_id', $parentId) as $child) {
                $descendants->push($child);
                $walk($child->id);
            }
        };

        $walk($category->id);

        return $descendants->values();
    }

    public function buildLineage(AccountCategory $category): Collection
    {
        $categories = $this->allOrdered()->keyBy('id');
        $lineage = collect([$category]);
        $current = $categories->get($category->parent_id);

        while ($current) {
            $lineage->prepend($current);
            $current = $categories->get($current->parent_id);
        }

        return $lineage->values();
    }

    public function attachParentAndChildren(Collection $categories): Collection
    {
        $categoriesById = $categories->keyBy('id');

        return $categories->map(function (AccountCategory $category) use ($categoriesById) {
            $parent = $category->parent_id ? $categoriesById->get($category->parent_id) : null;
            $children = $categoriesById->filter(fn (AccountCategory $child) => $child->parent_id === $category->id)->values();

            if ($parent) {
                $category->setRelation('parent', $parent);
            }

            $category->setRelation('children', $children);

            return $category;
        });
    }

    public function attachAccounts(Collection $categories, Collection $accounts): Collection
    {
        $accountsByCategory = $accounts->groupBy('category_id');

        return $categories->map(function (AccountCategory $category) use ($accountsByCategory) {
            $category->setRelation('accounts', $accountsByCategory->get($category->id, collect())->values());

            return $category;
        });
    }

    public function buildTree(?Collection $categories = null, ?Collection $accounts = null): Collection
    {
        $categories = $categories ? $categories->values() : $this->allOrdered();
        $accounts = $accounts ? $accounts->values() : Account::query()->orderBy('code')->get();

        $categoriesByParent = $categories->groupBy(fn (AccountCategory $category) => $category->parent_id ?? '__root__');
        $accountsByCategory = $accounts->groupBy('category_id');

        $buildNode = function (AccountCategory $category, array $path = []) use (&$buildNode, $categoriesByParent, $accountsByCategory): array {
            $currentPath = array_merge($path, [$category->category_name]);

            $children = $categoriesByParent->get($category->id, collect())
                ->map(fn (AccountCategory $child) => $buildNode($child, $currentPath))
                ->values();

            $directAccounts = $accountsByCategory->get($category->id, collect())
                ->map(function (Account $account) {
                    return [
                        'id' => $account->id,
                        'category_id' => $account->category_id,
                        'code' => $account->code,
                        'name' => $account->name,
                        'is_postable' => $account->is_postable,
                        'status' => $account->status,
                    ];
                })
                ->values();

            return [
                'id' => $category->id,
                'parent_id' => $category->parent_id,
                'type' => $category->type,
                'category_code' => $category->category_code,
                'category_name' => $category->category_name,
                'report_type' => $category->report_type,
                'sequence_no' => $category->sequence_no,
                'status' => $category->status,
                'path' => $currentPath,
                'children' => $children,
                'accounts' => $directAccounts,
            ];
        };

        return $categoriesByParent->get('__root__', collect())
            ->map(fn (AccountCategory $category) => $buildNode($category))
            ->values();
    }
}
