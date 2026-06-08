<?php

namespace ESolution\LaravelAccounting\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlyBalance extends Model
{
    use HasUuid;

    protected $fillable = [
        'fiscal_year',
        'fiscal_month',
        'account_id',
        'opening_balance',
        'total_debit',
        'total_credit',
        'ending_balance',
        'journal_count',
        'closed_at',
        'closed_by',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'total_debit' => 'decimal:2',
        'total_credit' => 'decimal:2',
        'ending_balance' => 'decimal:2',
        'closed_at' => 'datetime',
    ];

    public function getTable()
    {
        return config('accounting.table_prefix', 'acc_').'monthly_balances';
    }

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
