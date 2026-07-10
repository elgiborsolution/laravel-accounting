<?php

namespace ESolution\LaravelAccounting\Repositories;

use ESolution\LaravelAccounting\Enums\JournalStatus;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\JournalEntry;
use ESolution\LaravelAccounting\Models\JournalEntryDetail;
use ESolution\LaravelAccounting\Models\Service;
use Illuminate\Support\Collection;

class JournalRepository
{
    public function __construct(
        protected AccountRepository $accounts,
        protected ServiceRepository $services
    ) {}

    public function findById(string $id): ?JournalEntry
    {
        return JournalEntry::query()->find($id);
    }

    public function findWithDetails(string $id): ?JournalEntry
    {
        $journal = $this->findById($id);

        if (! $journal) {
            return null;
        }

        $details = JournalEntryDetail::query()
            ->where('journal_entry_id', $journal->id)
            ->orderBy('created_at')
            ->get();

        $journal->setRelation('details', $details);

        return $journal;
    }

    public function loadService(JournalEntry $journal): JournalEntry
    {
        $service = $journal->service_id ? $this->services->findById($journal->service_id) : null;

        if ($service) {
            $journal->setRelation('service', $service);
        }

        return $journal;
    }

    public function loadDetailsWithAccounts(JournalEntry $journal): JournalEntry
    {
        $details = $journal->relationLoaded('details')
            ? $journal->getRelation('details')
            : $this->findWithDetails($journal->id)?->getRelation('details') ?? collect();

        $accountIds = $details->pluck('account_id')->filter()->unique()->values();
        $accounts = $this->accounts->findManyByIds($accountIds);

        $details = $details->map(function (JournalEntryDetail $detail) use ($accounts) {
            $account = $accounts->get($detail->account_id);

            if ($account) {
                $detail->setRelation('account', $account);
            }

            return $detail;
        });

        $journal->setRelation('details', $details);

        return $journal;
    }

    public function loadReversals(JournalEntry $journal): JournalEntry
    {
        $reversals = JournalEntry::query()
            ->where('reversal_of_id', $journal->id)
            ->orderBy('created_at')
            ->get();

        $journal->setRelation('reversals', $reversals);

        return $journal;
    }

    public function attachViewRelations(JournalEntry $journal): JournalEntry
    {
        $journal = $this->loadService($journal);
        $journal = $this->loadDetailsWithAccounts($journal);
        $journal = $this->loadReversals($journal);

        $reversalOf = $journal->reversal_of_id ? $this->findById($journal->reversal_of_id) : null;

        if ($reversalOf) {
            $journal->setRelation('reversalOf', $reversalOf);
        }

        return $journal;
    }

    public function getPostedDetailsByAccount(string $accountId, string $startDate, string $endDate): Collection
    {
        $prefix = config('accounting.table_prefix', 'acc_');

        return JournalEntryDetail::query()
            ->select($prefix.'journal_entry_details.*')
            ->join($prefix.'journal_entries', $prefix.'journal_entries.id', '=', $prefix.'journal_entry_details.journal_entry_id')
            ->where('account_id', $accountId)
            ->whereBetween($prefix.'journal_entries.trx_date', [$startDate, $endDate])
            ->where($prefix.'journal_entries.status', JournalStatus::POSTED->value)
            ->orderBy($prefix.'journal_entry_details.created_at')
            ->get();
    }
}
