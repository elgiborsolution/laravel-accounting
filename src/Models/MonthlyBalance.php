<?php

namespace ESolution\LaravelAccounting\Models;

class MonthlyBalance extends AccountingModel
{
    protected string $baseTable = 'monthly_balances';

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

}
