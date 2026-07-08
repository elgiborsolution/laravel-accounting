<?php

namespace ESolution\LaravelAccounting\Models;

use ESolution\LaravelAccounting\Repositories\AccountCategoryRepository;
use Illuminate\Support\Collection;

class AccountCategory extends MasterDataModel
{
    protected string $baseTable = 'account_categories';

    protected $fillable = [
        'parent_id',
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

    public function setTypeAttribute($value)
    {
        $this->attributes['type'] = strtolower((string) $value);
    }

    public function getTypeAttribute($value)
    {
        return $value ? strtoupper($value) : $value;
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sequence_no')->orderBy('category_name');
    }

    public function accounts()
    {
        return $this->hasMany(Account::class, 'category_id')->orderBy('code');
    }

    public function descendantCategories(): Collection
    {
        return app(AccountCategoryRepository::class)->getDescendants($this);
    }

    public function lineage(): Collection
    {
        return app(AccountCategoryRepository::class)->buildLineage($this);
    }
}
