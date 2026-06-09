<?php

namespace ESolution\LaravelAccounting\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use HasUuid;

    protected $fillable = [
        'service_code',
        'service_name',
        'module_name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function getTable()
    {
        return config('accounting.table_prefix', 'acc_').'services';
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
