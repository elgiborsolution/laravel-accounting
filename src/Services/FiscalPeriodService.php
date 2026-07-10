<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Models\FiscalPeriod;
use ESolution\LaravelAccounting\Repositories\FiscalPeriodRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FiscalPeriodService
{
    public function __construct(protected FiscalPeriodRepository $periods) {}

    public function ensureForDate($date): FiscalPeriod
    {
        return $this->periods->firstOrCreateForDate(Carbon::parse($date));
    }

    public function ensureThroughCurrentMonth(?Carbon $fromDate = null): Collection
    {
        $startDate = $fromDate ? $fromDate->copy() : $this->periods->resolveEarliestJournalDate();

        if (! $startDate) {
            $startDate = now()->startOfMonth();
        }

        $startDate = $startDate->startOfMonth();

        return $this->periods->ensureThroughCurrentMonth($startDate);
    }

    public function ensureForJournalDate($date): FiscalPeriod
    {
        return $this->ensureForDate($date);
    }
}
