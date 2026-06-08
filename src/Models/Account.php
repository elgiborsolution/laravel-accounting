<?php

namespace ESolution\LaravelAccounting\Models;

use ESolution\LaravelAccounting\Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use HasFactory, HasUuid;

    protected static function newFactory()
    {
        return AccountFactory::new();
    }

    protected $fillable = [
        'category_id',
        'code',
        'name',
        'parent_id',
        'level',
        'is_postable',
        'status',
    ];

    protected $casts = [
        'is_postable' => 'boolean',
        'status' => 'boolean',
    ];

    public function getTable()
    {
        return config('accounting.table_prefix', 'acc_').'accounts';
    }

    public function category()
    {
        return $this->belongsTo(AccountCategory::class, 'category_id');
    }

    public function parent()
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function mappings()
    {
        return $this->hasMany(ServiceAccount::class, 'account_id');
    }
}
