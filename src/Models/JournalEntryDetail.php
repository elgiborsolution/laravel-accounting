<?php

namespace ESolution\LaravelAccounting\Models;

class JournalEntryDetail extends TransactionDataModel
{
    protected string $baseTable = 'journal_entry_details';

    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'debit',
        'credit',
        'description',
    ];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
    ];

    public function header()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

}
