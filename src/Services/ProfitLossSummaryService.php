<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Support\ProfitLossSummaryCalculator;

class ProfitLossSummaryService
{
    public function __construct(
        protected ReportService $reports,
        protected ProfitLossSummaryCalculator $calculator
    ) {}

    public function summarize($year, $month, array $tenantIds): array
    {
        $period = $this->calculator->period($year, $month);
        $mappings = $this->calculator->mappings();
        // The existing per-tenant report path validates all tenants and restores
        // tenancy/default connection in finally before returning materialized data.
        $reports = $this->reports->profitLoss($period['year'], $period['month'], $tenantIds);
        $summaries = [];
        foreach ($reports as $report) {
            $summaries[] = [
                'tenant_id' => $report['tenant_id'],
                'summary' => $this->calculator->calculate($report['report'], $mappings),
            ];
        }

        return [
            'tenant_ids' => array_column($reports, 'tenant_id'),
            'period' => $period,
            'summaries' => $summaries,
        ];
    }
}
