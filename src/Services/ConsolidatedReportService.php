<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Repositories\AccountCategoryRepository;
use ESolution\LaravelAccounting\Repositories\AccountRepository;
use ESolution\LaravelAccounting\Support\TenantReportRunner;
use ESolution\LaravelAccounting\Support\ProfitLossSummaryCalculator;
use Illuminate\Validation\ValidationException;

class ConsolidatedReportService
{
    public function __construct(
        protected ReportService $reports,
        protected TenantReportRunner $tenants,
        protected AccountCategoryRepository $categories,
        protected AccountRepository $accounts
    ) {}

    public function profitLoss($year, $month, array $tenantIds): array
    {
        return $this->consolidate('profitLoss', ['revenue' => 'REVENUE', 'expense' => 'EXPENSE'], $year, $month, $tenantIds);
    }

    public function balanceSheet($year, $month, array $tenantIds): array
    {
        return $this->consolidate('balanceSheet', ['asset' => 'ASSET', 'liability' => 'LIABILITY', 'equity' => 'EQUITY'], $year, $month, $tenantIds);
    }

    public function profitLossSummary($year, $month, array $tenantIds): array
    {
        $calculator = app(ProfitLossSummaryCalculator::class);
        $period = $calculator->period($year, $month);
        $mappings = $calculator->mappings();
        $report = $this->profitLoss($period['year'], $period['month'], $tenantIds);

        return [
            'tenant_ids' => $report['tenant_ids'],
            'period' => $period,
            'summary' => $calculator->calculate($report['report'], $mappings),
        ];
    }

    protected function consolidate(string $method, array $groups, $year, $month, array $tenantIds): array
    {
        validator(['year' => $year, 'month' => $month], [
            'year' => ['required', 'integer', 'min:1'],
            'month' => ['required', 'integer', 'between:1,12'],
        ])->validate();

        $sources = $this->tenants->run($tenantIds, function () use ($method, $year, $month) {
            return [
                'payload' => $this->reports->{$method}($year, $month),
                // Read metadata in the same context, including definitions
                // outside this report that might reuse a participating code.
                'definitions' => $this->definitions(),
            ];
        });

        $categories = [];
        $accounts = [];
        $balances = [];
        foreach ($sources as $source) {
            $seenCategories = [];
            $seenAccounts = [];
            foreach ($groups as $group => $type) {
                foreach ($source['report']['payload']['data'][$group] as $node) {
                    $this->collectNode(
                        $node, null, $source, $type,
                        $categories, $accounts, $balances, $seenCategories, $seenAccounts
                    );
                }
            }
        }

        // Check every occurrence of a participating code, even if another
        // tenant classifies it outside the requested report. Absence is valid.
        foreach ($sources as $source) {
            foreach (['categories' => $categories, 'accounts' => $accounts] as $kind => $definitions) {
                foreach ($definitions as $key => $definition) {
                    if (isset($source['report']['definitions'][$kind][$key])) {
                        $this->assertCompatible($kind, $definition, $source['report']['definitions'][$kind][$key], $source['tenant_id']);
                    }
                }
            }
        }

        uasort($categories, fn (array $a, array $b) => strcmp($a['category_code'], $b['category_code']));
        uasort($accounts, fn (array $a, array $b) => strcmp($a['code'], $b['code']));
        $children = [];
        $accountsByCategory = [];
        foreach ($categories as $definition) {
            $parent = $definition['parent_code'];
            $children[$parent === null ? '__root__' : 'category:'.$parent][] = $definition;
        }
        foreach ($accounts as $key => $definition) {
            $accountsByCategory['category:'.$definition['category_code']][] = $definition + ['balance' => $balances[$key]];
        }

        $data = [];
        foreach ($groups as $group => $type) {
            $data[$group] = [];
            foreach ($children['__root__'] ?? [] as $root) {
                if ($root['type'] === $type) {
                    $data[$group][] = $this->buildNode($root, $children, $accountsByCategory);
                }
            }
        }

        $report = ['data' => $data];
        if ($method === 'profitLoss') {
            $report['net_income'] = round($this->sumRoots($data['revenue']) - $this->sumRoots($data['expense']), 2);
        } else {
            foreach (['asset' => 'total_assets', 'liability' => 'total_liabilities', 'equity' => 'total_equity'] as $group => $total) {
                $report[$total] = $this->sumRoots($data[$group]);
            }
        }

        return [
            'tenant_ids' => array_column($sources, 'tenant_id'),
            'period' => ['year' => (int) $year, 'month' => (int) $month],
            'report' => $report,
        ];
    }

    protected function definitions(): array
    {
        $records = $this->categories->allOrdered()->keyBy('id');
        $categories = [];
        foreach ($records as $record) {
            $code = $this->code($record->category_code, 'category');
            $parent = $record->parent_id === null ? null : $records->get($record->parent_id);
            if ($record->parent_id !== null && ! $parent) {
                $this->invalid('Category ['.$code.'] has an unresolved parent.');
            }
            $key = 'category:'.$code;
            if (isset($categories[$key])) {
                $this->invalid('Duplicate category code ['.$code.'] in a tenant definition.');
            }
            $categories[$key] = [
                'category_code' => $code,
                'parent_code' => $parent ? $this->code($parent->category_code, 'category') : null,
                'type' => $record->type,
                'category_name' => $record->category_name,
                'report_type' => $record->report_type,
                'status' => (bool) $record->status,
            ];
        }

        $accounts = [];
        foreach ($this->accounts->allOrdered() as $record) {
            $code = $this->code($record->code, 'account');
            $category = $records->get($record->category_id);
            if (! $category) {
                $this->invalid('Account ['.$code.'] has an unresolved category.');
            }
            $key = 'account:'.$code;
            if (isset($accounts[$key])) {
                $this->invalid('Duplicate account code ['.$code.'] in a tenant definition.');
            }
            $accounts[$key] = [
                'code' => $code,
                'category_code' => $this->code($category->category_code, 'category'),
                'name' => $record->name,
                'is_postable' => (bool) $record->is_postable,
                'status' => (bool) $record->status,
            ];
        }

        return ['categories' => $categories, 'accounts' => $accounts];
    }

    protected function collectNode(
        array $node, ?string $parentCode, array $source, string $rootType,
        array &$categories, array &$accounts, array &$balances,
        array &$seenCategories, array &$seenAccounts
    ): void {
        $code = $this->code($node['category_code'] ?? null, 'category');
        $key = 'category:'.$code;
        $definition = $source['report']['definitions']['categories'][$key] ?? null;
        if (! $definition || $definition['parent_code'] !== $parentCode
            || ($parentCode === null && $definition['type'] !== $rootType)) {
            $this->invalid('Category ['.$code.'] does not match its report hierarchy in tenant ['.$source['tenant_id'].'].');
        }
        if (isset($seenCategories[$key])) {
            $this->invalid('Category ['.$code.'] occurs more than once in tenant ['.$source['tenant_id'].'].');
        }
        $seenCategories[$key] = true;
        $categories[$key] ??= $definition;

        foreach ($node['accounts'] as $account) {
            $accountCode = $this->code($account['code'] ?? null, 'account');
            $accountKey = 'account:'.$accountCode;
            $accountDefinition = $source['report']['definitions']['accounts'][$accountKey] ?? null;
            if (! $accountDefinition || $accountDefinition['category_code'] !== $code) {
                $this->invalid('Account ['.$accountCode.'] does not match its report category in tenant ['.$source['tenant_id'].'].');
            }
            if (isset($seenAccounts[$accountKey])) {
                $this->invalid('Account ['.$accountCode.'] occurs more than once in tenant ['.$source['tenant_id'].'].');
            }
            $amount = $account['balance'] ?? null;
            if (! is_numeric($amount) || ! is_finite((float) $amount)) {
                $this->invalid('Account ['.$accountCode.'] has an invalid balance in tenant ['.$source['tenant_id'].'].');
            }
            $seenAccounts[$accountKey] = true;
            $accounts[$accountKey] ??= $accountDefinition;
            $balances[$accountKey] = round(($balances[$accountKey] ?? 0.0) + (float) $amount, 2);
        }

        foreach ($node['children'] as $child) {
            $this->collectNode($child, $code, $source, $rootType, $categories, $accounts, $balances, $seenCategories, $seenAccounts);
        }
    }

    protected function assertCompatible(string $kind, array $expected, array $actual, string $tenantId): void
    {
        foreach ($expected as $field => $value) {
            if ($actual[$field] !== $value) {
                $code = $expected['category_code'];
                if ($kind === 'accounts') {
                    $code = $expected['code'];
                }
                $label = $kind === 'accounts' ? 'Account' : 'Category';
                $this->invalid($label.' code ['.$code.'] has incompatible ['.$field.'] in tenant ['.$tenantId.'].');
            }
        }
    }

    protected function buildNode(array $definition, array $children, array $accountsByCategory): array
    {
        $key = 'category:'.$definition['category_code'];
        $childNodes = [];
        foreach ($children[$key] ?? [] as $child) {
            $childNodes[] = $this->buildNode($child, $children, $accountsByCategory);
        }
        $accounts = $accountsByCategory[$key] ?? [];

        return $definition + [
            'children' => $childNodes,
            'accounts' => $accounts,
            'balance' => round(array_sum(array_column($accounts, 'balance')) + $this->sumRoots($childNodes), 2),
        ];
    }

    protected function sumRoots(array $nodes): float
    {
        return round(array_sum(array_column($nodes, 'balance')), 2);
    }

    protected function code(mixed $value, string $kind): string
    {
        if ((! is_string($value) && ! is_int($value)) || trim((string) $value) === '') {
            $this->invalid('A '.$kind.' definition is missing a stable code.');
        }

        return (string) $value;
    }

    protected function invalid(string $message): never
    {
        throw ValidationException::withMessages(['consolidation' => [$message]]);
    }
}
