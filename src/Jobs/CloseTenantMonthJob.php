<?php

namespace ESolution\LaravelAccounting\Jobs;

use ESolution\LaravelAccounting\Services\ClosingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Closes one tenant of a global monthly-closing run. The job holds only the tracking item id;
 * ClosingService::processGlobalCloseTenant() claims the item, rechecks the fiscal period and
 * records the outcome, so duplicate or redelivered jobs are harmless.
 */
class CloseTenantMonthJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 10;

    public function __construct(public int $itemId) {}

    public function handle(ClosingService $closing): void
    {
        $result = $closing->processGlobalCloseTenant($this->itemId);

        // Another worker holds this tenant/period; try again after it finishes or its lease expires.
        if (($result['reason'] ?? null) === 'busy' && $this->job !== null) {
            $this->release(60);
        }
    }
}
