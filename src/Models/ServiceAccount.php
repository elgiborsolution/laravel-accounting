<?php

namespace ESolution\LaravelAccounting\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceAccount extends Model
{
    use HasUuid;

    protected $fillable = [
        'service_id',
        'mapping_key',
        'mapping_name',
        'position',
        'account_id',
        'sequence_no',
        'is_dynamic',
        'is_required',
        'status',
        'is_active',
    ];

    protected $casts = [
        'is_dynamic' => 'boolean',
        'is_required' => 'boolean',
        'status' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function getTable()
    {
        return config('accounting.table_prefix', 'acc_').'service_accounts';
    }

    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function getIsActiveAttribute(): bool
    {
        return (bool) $this->attributes['status'];
    }

    public function setIsActiveAttribute(bool $value): void
    {
        $this->attributes['status'] = $value;
    }
}
