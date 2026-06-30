<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use Illuminate\Support\Collection;

class AccountCategoryTreeService
{
    public function getCategories(): Collection
    {
        return AccountCategory::orderBy('sequence_no')
            ->orderBy('category_name')
            ->get();
    }

    public function getTree(?Collection $categories = null, ?Collection $accounts = null): Collection
    {
        $categories = $categories ?? $this->getCategories();
        $accounts = $accounts ?? Account::orderBy('code')->get();

        $categoriesByParent = $categories->groupBy(function ($category) {
            return $category->parent_id ?? '__root__';
        });

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

    public function buildNode(AccountCategory $category, ?Collection $categories = null, ?Collection $accounts = null): array
    {
        return $this->findNodeById($this->getTree($categories, $accounts), $category->id) ?? [];
    }

    public function getDescendants(AccountCategory $category): Collection
    {
        return $category->descendantCategories();
    }

    public function buildPath(AccountCategory $category): array
    {
        return $category->lineage()->map(fn (AccountCategory $node) => $node->category_name)->all();
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
}
