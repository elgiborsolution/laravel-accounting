<?php

namespace ESolution\LaravelAccounting\Models;

class Service extends MasterDataModel
{
    protected string $baseTable = 'services';

    protected $fillable = [
        'service_code',
        'service_name',
        'module_name',
        'description',
        'is_active',
        'status',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function getStatusAttribute(): bool
    {
        return (bool) ($this->attributes['is_active'] ?? false);
    }

    public function setStatusAttribute($value): void
    {
        $this->attributes['is_active'] = (bool) $value;
    }

    public function accounts()
    {
        return $this->hasMany(ServiceAccount::class, 'service_id');
    }

    public function mappings()
    {
        return $this->accounts();
    }
}
