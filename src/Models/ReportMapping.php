<?php

namespace ESolution\LaravelAccounting\Models;

use ESolution\LaravelAccounting\Enums\ReportType;
use Illuminate\Database\Eloquent\Model;

class ReportMapping extends Model
{
    use HasUuid;

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

    public function getTable()
    {
        return config('accounting.table_prefix', 'acc_').'report_mappings';
    }

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
