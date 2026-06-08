<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Enums\JournalStatus;
use ESolution\LaravelAccounting\Exceptions\AccountingPeriodLockedException;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\FiscalPeriod;
use ESolution\LaravelAccounting\Models\JournalEntry;
use ESolution\LaravelAccounting\Models\Service;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class JournalService
{
    /**
     * Create a journal entry using account mapping.
     */
    public function journalByMapping(array $data)
    {
        return DB::transaction(function () use ($data) {
            // 1. Validate service_code exists
            $serviceCode = $data['service_code'] ?? null;
            if (! $serviceCode) {
                throw new Exception('service_code is required for journal mapping');
            }

            $service = Service::where('service_code', $serviceCode)
                ->where('is_active', true)
                ->first();

            if (! $service) {
                throw new Exception("Service code not found or inactive: {$serviceCode}");
            }

            // 2. Load active mappings for this service
            $serviceMappings = $service->accounts()
                ->where('status', true)
                ->get()
                ->keyBy('mapping_key');

            $totalDebit = 0;
            $totalCredit = 0;
            $details = [];

            // 3. Validate items provided in data against service mappings
            $providedItems = collect($data['items'] ?? []);

            if ($providedItems->isEmpty()) {
                throw new Exception('Journal items cannot be empty');
            }

            foreach ($providedItems as $item) {
                $mappingKey = $item['mapping_key'] ?? null;
                if (! $mappingKey) {
                    throw new Exception('mapping_key is required for each item');
                }

                if (! $serviceMappings->has($mappingKey)) {
                    throw new Exception("Mapping key '{$mappingKey}' is not associated with service '{$serviceCode}'");
                }

                $mapping = $serviceMappings->get($mappingKey);

                $isDebit = $mapping->position === 'D';
                $isCredit = $mapping->position === 'K';

                $amount = $item['amount'] ?? 0;
                $debit = $isDebit ? $amount : 0;
                $credit = $isCredit ? $amount : 0;

                $accountId = ($mapping->is_dynamic && isset($item['account_id']))
                    ? $item['account_id']
                    : $mapping->account_id;

                if (! $accountId && $mapping->is_required) {
                    throw new Exception("Account mapping required but account_id not provided for dynamic key: {$mappingKey}");
                }

                $totalDebit += $debit;
                $totalCredit += $credit;

                $details[] = [
                    'account_id' => $accountId,
                    'debit' => $debit,
                    'credit' => $credit,
                    'description' => $item['description'] ?? $mapping->mapping_name,
                ];
            }

            // 4. Validate all required mappings for the service are provided
            foreach ($serviceMappings as $key => $mapping) {
                if ($mapping->is_required) {
                    $provided = $providedItems->firstWhere('mapping_key', $key);
                    if (! $provided) {
                        throw new Exception("Required mapping key '{$key}' is missing for service '{$serviceCode}'");
                    }
                }
            }

            $trxDate = isset($data['trx_date']) ? \Illuminate\Support\Carbon::parse($data['trx_date']) : now();

            $this->checkPeriodLocked($trxDate);

            if (round($totalDebit, 2) !== round($totalCredit, 2)) {
                throw new Exception("Journal is not balanced. Total Debit: $totalDebit, Total Credit: $totalCredit");
            }

            $journal = JournalEntry::create([
                'journal_no' => $this->generateJournalNo($trxDate),
                'trx_date' => $trxDate,
                'service_id' => $service->id,
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'reference_no' => $data['reference_no'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => JournalStatus::DRAFT,
            ]);

            foreach ($details as $detail) {
                $journal->details()->create($detail);
            }

            if (config('accounting.journal.auto_post', true)) {
                $this->post($journal->id);
            }

            $this->clearCache();

            return $journal;
        });
    }

    /**
     * Create a manual journal entry.
     */
    public function journalManual(array $data)
    {
        return DB::transaction(function () use ($data) {
            if (! isset($data['items']) || empty($data['items'])) {
                throw new Exception('Journal items are required');
            }

            $totalDebit = 0;
            $totalCredit = 0;
            $details = [];

            foreach ($data['items'] as $index => $item) {
                // 1. Resolve Account ID
                $accountId = $item['account_id'] ?? null;
                $accountCode = $item['account_code'] ?? null;

                if (! $accountId && ! $accountCode) {
                    throw new Exception("Item at index {$index} must have either account_id or account_code");
                }

                if (! $accountId) {
                    $account = Account::where('code', $accountCode)->first();
                    if (! $account) {
                        throw new Exception("Account code not found: {$accountCode}");
                    }
                    $accountId = $account->id;
                } else {
                    // Verify ID exists if provided
                    if (! Account::where('id', $accountId)->exists()) {
                        throw new Exception("Account ID not found: {$accountId}");
                    }
                }

                // 2. Calculate Debit/Credit
                $type = strtolower($item['type'] ?? '');
                $amount = $item['amount'] ?? 0;

                $isDebit = in_array($type, ['d', 'debit']);
                $isCredit = in_array($type, ['k', 'credit']);

                if (! $isDebit && ! $isCredit) {
                    throw new Exception("Item at index {$index} must have a valid type (D/Debit or K/Credit)");
                }

                $debit = $isDebit ? $amount : 0;
                $credit = $isCredit ? $amount : 0;

                $totalDebit += $debit;
                $totalCredit += $credit;

                $details[] = [
                    'account_id' => $accountId,
                    'debit' => $debit,
                    'credit' => $credit,
                    'description' => $item['description'] ?? null,
                ];
            }

            $trxDate = isset($data['trx_date']) ? \Illuminate\Support\Carbon::parse($data['trx_date']) : now();

            $this->checkPeriodLocked($trxDate);

            if (round($totalDebit, 2) !== round($totalCredit, 2)) {
                throw new Exception("Journal is not balanced. Total Debit: $totalDebit, Total Credit: $totalCredit");
            }

            $journal = JournalEntry::create([
                'journal_no' => $this->generateJournalNo($trxDate),
                'trx_date' => $trxDate,
                'description' => $data['description'] ?? null,
                'status' => JournalStatus::DRAFT,
            ]);

            foreach ($details as $detail) {
                $journal->details()->create($detail);
            }

            if (config('accounting.journal.auto_post', true)) {
                $this->post($journal->id);
            }

            $this->clearCache();

            return $journal;
        });
    }

    /**
     * Post a journal entry.
     */
    public function post($id)
    {
        $journal = JournalEntry::findOrFail($id);

        $this->checkPeriodLocked($journal->trx_date);

        if ($journal->status === JournalStatus::POSTED) {
            $this->clearCache();

            return $journal;
        }

        $journal->update([
            'status' => JournalStatus::POSTED,
            'posted_at' => now(),
            'posted_by' => auth()->id() ?? null,
        ]);

        $this->clearCache();

        return $journal;
    }

    protected function getTenantId()
    {
        if (function_exists('tenancy') && tenancy()->initialized) {
            return tenancy()->tenant->id;
        }

        return null;
    }

    protected function clearCache()
    {
        $tenantId = $this->getTenantId();
        $tags = ['acc_journals'];

        if ($tenantId) {
            $tags[] = 'acc_journals_tenant_'.$tenantId;
        }

        Cache::tags($tags)->flush();
    }

    protected function checkPeriodLocked($date)
    {
        $date = \Illuminate\Support\Carbon::parse($date);
        $period = FiscalPeriod::where('year', $date->year)
            ->where('month', $date->month)
            ->first();

        if ($period && $period->is_closed) {
            throw new AccountingPeriodLockedException;
        }
    }

    protected function generateJournalNo($date = null)
    {
        $format = config('accounting.journal.number_format', 'JV/{YEAR}/{MONTH}/{SEQ}');
        $date = $date ? \Illuminate\Support\Carbon::parse($date) : now();
        $year = $date->format('Y');
        $month = $date->format('m');

        $lastJournal = JournalEntry::whereYear('trx_date', $year)
            ->whereMonth('trx_date', $month)
            ->orderBy('journal_no', 'desc')
            ->first();

        $seq = 1;
        if ($lastJournal) {
            // Extract sequence from the last journal number based on format
            // This is a simplified approach, assuming SEQ is at the end
            $lastSeq = (int) substr($lastJournal->journal_no, -4);
            $seq = $lastSeq + 1;
        }

        $seqStr = str_pad($seq, 4, '0', STR_PAD_LEFT);

        return str_replace(['{YEAR}', '{MONTH}', '{SEQ}'], [$year, $month, $seqStr], $format);
    }
}
