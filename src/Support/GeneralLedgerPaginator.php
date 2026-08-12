<?php

namespace ESolution\LaravelAccounting\Support;

use Illuminate\Pagination\LengthAwarePaginator;

class GeneralLedgerPaginator extends LengthAwarePaginator
{
    public function __construct(
        mixed $items,
        int $total,
        int $perPage,
        ?int $currentPage,
        array $options,
        protected array $ledger
    ) {
        parent::__construct($items, $total, $perPage, $currentPage, $options);
    }

    /**
     * Keep Laravel's native paginator payload and expose ledger-wide values
     * alongside it. The values are calculated for the complete period.
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), $this->ledger);
    }
}
