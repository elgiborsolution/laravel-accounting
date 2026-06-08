<?php

namespace ESolution\LaravelAccounting\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalPeriod extends Model
{
    use HasUuid;

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

    public function getTable()
    {
        return config('accounting.table_prefix', 'acc_').'fiscal_periods';
    }
}
