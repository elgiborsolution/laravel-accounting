<?php

namespace ESolution\LaravelAccounting\Models;

use ESolution\LaravelAccounting\Enums\JournalStatus;
use Exception;

class JournalEntry extends TransactionDataModel
{
    protected string $baseTable = 'journal_entries';

    protected $fillable = [
        'journal_no',
        'trx_date',
        'service_id',
        'source_type',
        'source_id',
        'reference_no',
        'description',
        'amount',
        'status',
        'posted_by',
        'posted_at',
        'reversal_of_id',
        'reversal_reason',
        'reversed_at',
        'is_reversal',
    ];

    protected $casts = [
        'trx_date' => 'date',
        'amount' => 'decimal:2',
        'status' => JournalStatus::class,
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
        'is_reversal' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $journal) {
            if ($journal->getOriginal('status') === JournalStatus::POSTED->value && $journal->isDirty()) {
                throw new Exception('Posted journals are immutable. Create a reversing journal instead of editing existing entries.');
            }
        });
    }

    public function details()
    {
        return $this->hasMany(JournalEntryDetail::class, 'journal_entry_id');
    }

    public function reversalOf()
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversals()
    {
        return $this->hasMany(self::class, 'reversal_of_id');
    }

    public function getTypeAttribute(): string
    {
        return $this->is_reversal ? 'reversal' : 'journal';
    }
}
