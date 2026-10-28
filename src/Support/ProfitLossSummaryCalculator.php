<?php

namespace ESolution\LaravelAccounting\Support;

use Illuminate\Validation\ValidationException;

class ProfitLossSummaryCalculator
{
    public const DEFAULT_MAPPINGS = [
        'account_mappings' => [
            '5210' => 'operating_expenses',
            '5601' => 'sales_discounts',
            '5701' => 'sales_returns',
        ],
        'category_mappings' => [
            'SALES_REVENUE' => 'sales_revenue',
            'SERVICE_REVENUE' => 'sales_revenue',
            'COST_OF_GOODS_SOLD' => 'cogs',
            'OPERATING_EXPENSE' => 'operating_expenses',
            'OTHER_REVENUE' => 'non_operating_income',
            'OTHER_EXPENSE' => 'non_operating_expenses',
        ],
    ];

    public const COMPONENTS = [
        'sales_revenue', 'sales_returns', 'sales_discounts', 'cogs',
        'other_operating_income', 'operating_expenses',
        'non_operating_income', 'non_operating_expenses', 'income_tax',
    ];

    public function period($year, $month): array
    {
        validator(['year' => $year, 'month' => $month], [
            'year' => ['required', 'integer', 'min:1'],
            'month' => ['required', 'integer', 'between:1,12'],
        ])->validate();

        return ['year' => (int) $year, 'month' => (int) $month];
    }

    /** Snapshot one classification contract for every selected tenant. */
    public function mappings(): array
    {
        $configured = config('accounting.profit_loss_summary', []);
        if (! is_array($configured)) {
            $this->invalid('Summary mappings must be an array.', 'summary_mappings');
        }

        $mappings = [];
        foreach (self::DEFAULT_MAPPINGS as $kind => $defaults) {
            $overrides = array_key_exists($kind, $configured) ? $configured[$kind] : [];
            if (! is_array($overrides)) {
                $this->invalid('Summary '.$kind.' must be an array.', 'summary_mappings');
            }
            foreach ($overrides as $code => $component) {
                if (trim((string) $code) === '' || ! in_array($component, self::COMPONENTS, true)) {
                    $this->invalid('Invalid summary classification for '.$kind.' code ['.$code.'].', 'summary_mappings');
                }
            }
            $mappings[$kind] = array_replace($defaults, $overrides);
        }

        return $mappings;
    }

    public function calculate(array $report, array $mappings): array
    {
        $components = array_fill_keys(self::COMPONENTS, 0.0);
        $seenAccounts = [];
        $seenCategories = [];
        foreach (['revenue', 'expense'] as $group) {
            foreach ($report['data'][$group] as $node) {
                $this->collect($node, null, $mappings, $components, $seenAccounts, $seenCategories);
            }
        }

        $netSales = round($components['sales_revenue'] - $components['sales_returns'] - $components['sales_discounts'], 2);
        $grossProfit = round($netSales - $components['cogs'], 2);
        $operatingProfit = round($grossProfit + $components['other_operating_income'] - $components['operating_expenses'], 2);
        $netProfit = round($operatingProfit + $components['non_operating_income'] - $components['non_operating_expenses'] - $components['income_tax'], 2);

        return $components + [
            'net_sales' => $netSales,
            'gross_profit' => $grossProfit,
            'operating_profit' => $operatingProfit,
            'net_profit' => $netProfit,
        ];
    }

    protected function collect(
        array $node, ?string $inheritedComponent, array $mappings,
        array &$components, array &$seenAccounts, array &$seenCategories
    ): void {
        $categoryCode = $this->code($node['category_code'] ?? null, 'category');
        $categoryKey = 'category:'.$categoryCode;
        if (isset($seenCategories[$categoryKey])) {
            $this->invalid('Category ['.$categoryCode.'] occurs more than once in the profit/loss report.');
        }
        $seenCategories[$categoryKey] = true;
        $type = strtoupper((string) ($node['type'] ?? ''));
        if (! in_array($type, ['REVENUE', 'EXPENSE'], true)) {
            $this->invalid('Category ['.$categoryCode.'] has an invalid profit/loss type.');
        }
        $categoryComponent = $mappings['category_mappings'][$categoryCode] ?? $inheritedComponent;

        foreach ($node['accounts'] as $account) {
            $code = $this->code($account['code'] ?? null, 'account');
            $key = 'account:'.$code;
            if (isset($seenAccounts[$key])) {
                $this->invalid('Account ['.$code.'] occurs more than once in the profit/loss report.');
            }
            $seenAccounts[$key] = true;
            $balance = $account['balance'] ?? null;
            if (! is_numeric($balance) || ! is_finite((float) $balance)) {
                $this->invalid('Account ['.$code.'] has an invalid balance.');
            }

            $component = $mappings['account_mappings'][$code] ?? $categoryComponent;
            if ($component === null) {
                if ((float) $balance != 0.0) {
                    $this->invalid('Account ['.$code.'] in category ['.$categoryCode.'] has no summary classification.');
                }
                continue;
            }

            // Report balances follow category normal balance. Express income
            // as credit-positive and deductions as debit-positive, preserving
            // reversals instead of taking absolute values of contra accounts.
            $income = in_array($component, ['sales_revenue', 'other_operating_income', 'non_operating_income'], true);
            $amount = $income === ($type === 'REVENUE') ? (float) $balance : -(float) $balance;
            $components[$component] = round($components[$component] + $amount, 2);
        }

        foreach ($node['children'] as $child) {
            $this->collect($child, $categoryComponent, $mappings, $components, $seenAccounts, $seenCategories);
        }
    }

    protected function code(mixed $value, string $kind): string
    {
        if ((! is_string($value) && ! is_int($value)) || trim((string) $value) === '') {
            $this->invalid('A '.$kind.' is missing its stable code.');
        }

        return (string) $value;
    }

    protected function invalid(string $message, string $field = 'summary'): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
