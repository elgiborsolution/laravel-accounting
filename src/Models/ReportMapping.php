<?php

namespace ESolution\LaravelAccounting\Models;

use ESolution\LaravelAccounting\Enums\ReportType;

class ReportMapping extends AccountingModel
{
    protected string $baseTable = 'report_mappings';

    protected $fillable = [
        'account_id',
        'report_type',
        'report_group',
        'report_subgroup',
        'sequence_no',
        'is_active',
    ];

    protected $casts = [
        'report_type' => ReportType::class,
        'is_active' => 'boolean',
    ];

}
