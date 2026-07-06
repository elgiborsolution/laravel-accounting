<?php

namespace ESolution\LaravelAccounting\Models;

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
        $descendants = collect();

        $this->loadMissing('children');

        foreach ($this->children as $child) {
            $descendants->push($child);
            $descendants = $descendants->merge($child->descendantCategories());
        }

        return $descendants->values();
    }

    public function lineage(): Collection
    {
        $lineage = collect([$this]);
        $current = $this->parent;

        while ($current) {
            $lineage->prepend($current);
            $current = $current->parent;
        }

        return $lineage->values();
    }
}
