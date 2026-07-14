<?php

namespace ESolution\LaravelAccounting\Models;

use ESolution\LaravelAccounting\Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Account extends MasterDataModel
{
    use HasFactory;

    protected string $baseTable = 'accounts';

    protected static function newFactory()
    {
        return AccountFactory::new();
    }

    protected $fillable = [
        'category_id',
        'code',
        'name',
        'description',
        'is_postable',
        'status',
    ];

    protected $casts = [
        'is_postable' => 'boolean',
        'status' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(AccountCategory::class, 'category_id');
    }

    public function mappings()
    {
        return $this->hasMany(ServiceAccount::class, 'account_id');
    }
}
