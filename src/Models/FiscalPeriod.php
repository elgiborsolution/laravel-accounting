<?php

namespace ESolution\LaravelAccounting\Models;

class FiscalPeriod extends AccountingModel
{
    protected string $baseTable = 'fiscal_periods';

    protected $fillable = [
        'year',
        'month',
        'start_date',
        'end_date',
        'is_closed',
        'closed_at',
        'closed_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_closed' => 'boolean',
        'closed_at' => 'datetime',
    ];
}
