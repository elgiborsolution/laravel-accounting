<?php

namespace ESolution\LaravelAccounting\Repositories;

use ESolution\LaravelAccounting\Models\FiscalPeriod;
use ESolution\LaravelAccounting\Models\JournalEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FiscalPeriodRepository
{
    public function firstOrCreateForDate(Carbon $date): FiscalPeriod
    {
        return FiscalPeriod::firstOrCreate(
            [
                'year' => $date->year,
                'month' => $date->month,
            ],
            [
                'start_date' => $date->copy()->startOfMonth()->toDateString(),
                'end_date' => $date->copy()->endOfMonth()->toDateString(),
                'is_closed' => false,
                'closed_at' => null,
                'closed_by' => null,
            ]
        );
    }

    public function ensureThroughCurrentMonth(?Carbon $fromDate = null): Collection
    {
        $startDate = $fromDate ? $fromDate->copy() : $this->resolveEarliestJournalDate();

        if (! $startDate) {
            $startDate = now()->startOfMonth();
        }

        $startDate = $startDate->startOfMonth();
        $endDate = now()->startOfMonth();

        $periods = collect();

        while ($startDate->lte($endDate)) {
            $periods->push($this->firstOrCreateForDate($startDate));
            $startDate->addMonth();
        }

        return $periods;
    }

    public function resolveEarliestJournalDate(): ?Carbon
    {
        $date = JournalEntry::min('trx_date');

        return $date ? Carbon::parse($date) : null;
    }
}
