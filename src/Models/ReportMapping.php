<?php

namespace ESolution\LaravelAccounting\Models;

use ESolution\LaravelAccounting\Enums\ReportType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportMapping extends MasterDataModel
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

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
