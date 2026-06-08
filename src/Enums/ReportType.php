<?php

namespace ESolution\LaravelAccounting\Enums;

enum ReportType: string
{
    case PROFIT_LOSS = 'PL';
    case BALANCE_SHEET = 'BS';
    case CASH_FLOW = 'CF';
}
