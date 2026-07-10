<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Models\AccountCategory;
use ESolution\LaravelAccounting\Repositories\AccountCategoryRepository;
use ESolution\LaravelAccounting\Repositories\AccountRepository;
use Illuminate\Support\Collection;

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
}
