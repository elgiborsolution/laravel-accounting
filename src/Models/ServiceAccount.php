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
    ];

    protected $casts = [
        'is_dynamic' => 'boolean',
        'is_required' => 'boolean',
        'status' => 'boolean',
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
}
