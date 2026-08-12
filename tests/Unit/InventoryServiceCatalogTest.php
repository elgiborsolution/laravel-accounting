<?php

namespace ESolution\LaravelAccounting\Tests\Unit;

use ESolution\LaravelAccounting\Enums\AccountingServiceCode;
use ESolution\LaravelAccounting\Support\ServiceAccountTemplateRegistry;
use ESolution\LaravelAccounting\Support\ServiceCatalog;
use Tests\TestCase;

class InventoryServiceCatalogTest extends TestCase
{
    public function test_inventory_catalog_keeps_only_supported_adjustment_services(): void
    {
        $catalog = app(ServiceCatalog::class);
        $templates = app(ServiceAccountTemplateRegistry::class);

        $inventoryCodes = collect($catalog->inventory())->pluck('service_code')->all();

        $this->assertSame([
            AccountingServiceCode::STOCK_OPENING->value,
            AccountingServiceCode::STOCK_ADJUSTMENT_PLUS->value,
            AccountingServiceCode::STOCK_ADJUSTMENT_MINUS->value,
            AccountingServiceCode::STOCK_TRANSFER->value,
        ], $inventoryCodes);
        $this->assertCount(2, $templates->forService(AccountingServiceCode::STOCK_ADJUSTMENT_PLUS));
        $this->assertCount(2, $templates->forService(AccountingServiceCode::STOCK_ADJUSTMENT_MINUS));
    }
}
