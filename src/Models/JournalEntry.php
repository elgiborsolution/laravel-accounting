<?php

namespace ESolution\LaravelAccounting\Models;

use ESolution\LaravelAccounting\Enums\JournalStatus;
use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    use HasUuid;

    protected $fillable = [
        'journal_no',
        'trx_date',
        'service_id',
        'source_type',
        'source_id',
        'reference_no',
        'description',
        'status',
        'posted_by',
        'posted_at',
    ];

    protected $casts = [
        'trx_date' => 'date',
        'status' => JournalStatus::class,
        'posted_at' => 'datetime',
    ];

    public function getTable()
    {
        return config('accounting.table_prefix', 'acc_').'journal_entries';
    }

    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function details()
    {
        return $this->hasMany(JournalEntryDetail::class, 'journal_entry_id');
    }
}
