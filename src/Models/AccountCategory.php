<?php

namespace ESolution\LaravelAccounting\Models;

use Illuminate\Database\Eloquent\Model;

class AccountCategory extends Model
{
    use HasUuid;

    protected $fillable = [
        'type',
        'category_code',
        'category_name',
        'report_type',
        'sequence_no',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function getTable()
    {
        return config('accounting.table_prefix', 'acc_').'account_categories';
    }

    public function accounts()
    {
        return $this->hasMany(Account::class, 'category_id');
    }
}
