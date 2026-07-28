<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\FiscalPeriod;
use ESolution\LaravelAccounting\Models\JournalEntry;
use ESolution\LaravelAccounting\Models\JournalEntryDetail;
use ESolution\LaravelAccounting\Support\AccountingConnectionResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class AccountOpeningBalanceService
{
    public const SOURCE_TYPE = 'ACCOUNT_OPENING_BALANCE';

    public const OPENING_BALANCE_EQUITY_CODE = '3001';

    public function __construct(
        protected JournalService $journals,
        protected AccountingConnectionResolver $connections
    ) {}

    public function createAccount(array $attributes): Account
    {
        return $this->runAtomic(function () use ($attributes) {
            $account = Account::create($this->extractAccountAttributes($attributes));

            $this->applyOpeningBalanceIfRequested($account, $attributes);

            return $account->fresh();
        });
    }

    public function updateAccount(Account $account, array $attributes): Account
    {
        return $this->runAtomic(function () use ($account, $attributes) {
            $openingBalanceJournal = $this->findOpeningBalanceJournal($account);

            $account->update($this->extractAccountAttributes($attributes));
            $account->refresh();

            if ($openingBalanceJournal) {
                $this->updateOpeningBalanceIfRequested($account, $openingBalanceJournal, $attributes);
            } else {
                $this->applyOpeningBalanceIfRequested($account, $attributes);
            }

            return $account->fresh();
        });
    }

    public function resolveOpeningBalanceData(Account $account): ?array
    {
        $journal = $this->findOpeningBalanceJournal($account);

        if (! $journal) {
            return null;
        }

        return [
            'amount' => (float) $journal->amount,
            'date' => optional($journal->trx_date)->toDateString(),
            'journal_entry_id' => $journal->id,
            'can_edit' => ! $this->hasBlockingJournalTransactions($journal),
        ];
    }

    protected function applyOpeningBalanceIfRequested(Account $account, array $attributes): void
    {
        $amount = $this->resolveOpeningBalanceAmount($attributes);

        if ($amount <= 0) {
            return;
        }

        if (! array_key_exists('opening_balance_date', $attributes) || ! $attributes['opening_balance_date']) {
            throw ValidationException::withMessages([
                'opening_balance_date' => ['The opening balance date field is required when opening balance is present.'],
            ]);
        }

        if ($this->hasOpeningBalanceJournal($account)) {
            throw ValidationException::withMessages([
                'opening_balance' => ['Opening balance has already been set for this account.'],
            ]);
        }

        if (! $account->status) {
            throw ValidationException::withMessages([
                'opening_balance' => ['Account must be active to set an opening balance.'],
            ]);
        }

        if (! $account->is_postable) {
            throw ValidationException::withMessages([
                'opening_balance' => ['Account must be postable to set an opening balance.'],
            ]);
        }

        $account->loadMissing('category');
        $contraAccount = Account::query()
            ->where('code', self::OPENING_BALANCE_EQUITY_CODE)
            ->first();

        if (! $contraAccount) {
            throw ValidationException::withMessages([
                'opening_balance' => ['Opening Balance Equity account is not configured.'],
            ]);
        }

        $accountLineType = $this->isDebitNormalBalance($account) ? 'D' : 'K';
        $contraLineType = $accountLineType === 'D' ? 'K' : 'D';
        $description = 'Opening Balance - '.$account->name;

        $this->journals->journalManual([
            'trx_date' => $attributes['opening_balance_date'],
            'reference_no' => 'OPENING-'.$account->code,
            'description' => $description,
            'source_type' => self::SOURCE_TYPE,
            'source_id' => $account->id,
            'details' => [
                [
                    'account_id' => $account->id,
                    'type' => $accountLineType,
                    'amount' => $amount,
                    'description' => $description,
                ],
                [
                    'account_id' => $contraAccount->id,
                    'type' => $contraLineType,
                    'amount' => $amount,
                    'description' => $description,
                ],
            ],
        ], false);
    }

    protected function updateOpeningBalanceIfRequested(Account $account, JournalEntry $journal, array $attributes): void
    {
        if (! $this->hasOpeningBalancePayload($attributes)) {
            return;
        }

        $currentDate = optional($journal->trx_date)->toDateString();
        $targetDate = array_key_exists('opening_balance_date', $attributes) && $attributes['opening_balance_date']
            ? Carbon::parse($attributes['opening_balance_date'])->toDateString()
            : $currentDate;
        $targetAmount = array_key_exists('opening_balance', $attributes) && $attributes['opening_balance'] !== null
            ? round((float) $attributes['opening_balance'], 2)
            : (float) $journal->amount;

        if ($targetAmount <= 0) {
            return;
        }

        if ($this->hasBlockingJournalTransactions($journal, $targetDate)) {
            throw ValidationException::withMessages([
                'opening_balance' => ['Opening Balance cannot be edited because journal transactions already exist.'],
            ]);
        }

        if (! $account->status) {
            throw ValidationException::withMessages([
                'opening_balance' => ['Account must be active to set an opening balance.'],
            ]);
        }

        if (! $account->is_postable) {
            throw ValidationException::withMessages([
                'opening_balance' => ['Account must be postable to set an opening balance.'],
            ]);
        }

        $account->loadMissing('category');
        $contraAccount = Account::query()
            ->where('code', self::OPENING_BALANCE_EQUITY_CODE)
            ->first();

        if (! $contraAccount) {
            throw ValidationException::withMessages([
                'opening_balance' => ['Opening Balance Equity account is not configured.'],
            ]);
        }

        $description = 'Opening Balance - '.$account->name;
        $accountLineType = $this->isDebitNormalBalance($account) ? 'D' : 'K';
        $contraLineType = $accountLineType === 'D' ? 'K' : 'D';

        $this->replaceOpeningBalanceJournal($journal, $account, $contraAccount, $targetAmount, $targetDate, $description, $accountLineType, $contraLineType);
    }

    protected function hasOpeningBalancePayload(array $attributes): bool
    {
        return array_key_exists('opening_balance', $attributes)
            || array_key_exists('opening_balance_date', $attributes);
    }

    protected function hasOpeningBalanceJournal(Account $account): bool
    {
        return $this->findOpeningBalanceJournal($account) !== null;
    }

    protected function findOpeningBalanceJournal(Account $account): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('source_type', self::SOURCE_TYPE)
            ->where('source_id', $account->id)
            ->first();
    }

    protected function resolveOpeningBalanceAmount(array $attributes): float
    {
        if (! array_key_exists('opening_balance', $attributes) || $attributes['opening_balance'] === null) {
            return 0;
        }

        return round((float) $attributes['opening_balance'], 2);
    }

    protected function extractAccountAttributes(array $attributes): array
    {
        return collect($attributes)->only([
            'category_id',
            'tenant_id',
            'code',
            'name',
            'description',
            'is_postable',
            'status',
        ])->all();
    }

    protected function isDebitNormalBalance(Account $account): bool
    {
        $categoryType = strtoupper((string) ($account->category?->type ?? ''));

        return in_array($categoryType, ['ASSET', 'EXPENSE'], true);
    }

    protected function hasBlockingJournalTransactions(JournalEntry $journal, string|Carbon|null $compareDate = null): bool
    {
        $journalDate = optional($journal->trx_date)->toDateString();
        $compareDate = $compareDate ? Carbon::parse($compareDate)->toDateString() : $journalDate;

        if ($journalDate !== null && $compareDate !== null && $compareDate > $journalDate) {
            $compareDate = $journalDate;
        }

        if ($compareDate === null) {
            return false;
        }

        return JournalEntry::query()
            ->whereKeyNot($journal->id)
            ->whereDate('trx_date', '>=', $compareDate)
            ->exists();
    }

    protected function replaceOpeningBalanceJournal(
        JournalEntry $journal,
        Account $account,
        Account $contraAccount,
        float $amount,
        string $trxDate,
        string $description,
        string $accountLineType,
        string $contraLineType
    ): void {
        $date = Carbon::parse($trxDate);
        app(FiscalPeriodService::class)->ensureForJournalDate($date);

        $period = FiscalPeriod::query()
            ->where('year', $date->year)
            ->where('month', $date->month)
            ->first();

        if ($period && $period->is_closed) {
            throw ValidationException::withMessages([
                'trx_date' => ['The selected fiscal period is closed.'],
            ]);
        }

        $details = JournalEntryDetail::query()
            ->where('journal_entry_id', $journal->id)
            ->get()
            ->keyBy('account_id');

        $accountDetail = $details->get($account->id);
        $contraDetail = $details->get($contraAccount->id);

        if (! $accountDetail || ! $contraDetail) {
            throw ValidationException::withMessages([
                'opening_balance' => ['Opening balance journal details are invalid.'],
            ]);
        }

        $transactionConnection = $this->connections->resolveTransactionDataConnection();

        DB::connection($transactionConnection)
            ->table($journal->getTable())
            ->where('id', $journal->id)
            ->update([
                'trx_date' => $trxDate,
                'description' => $description,
                'amount' => round($amount, 2),
            ]);

        DB::connection($transactionConnection)
            ->table($accountDetail->getTable())
            ->where('id', $accountDetail->id)
            ->update([
                'debit' => $accountLineType === 'D' ? round($amount, 2) : 0,
                'credit' => $accountLineType === 'K' ? round($amount, 2) : 0,
                'description' => $description,
            ]);

        DB::connection($transactionConnection)
            ->table($contraDetail->getTable())
            ->where('id', $contraDetail->id)
            ->update([
                'debit' => $contraLineType === 'D' ? round($amount, 2) : 0,
                'credit' => $contraLineType === 'K' ? round($amount, 2) : 0,
                'description' => $description,
            ]);

        $tags = ['acc_journals'];
        if ($account->tenant_id) {
            $tags[] = 'acc_journals_tenant_'.$account->tenant_id;
        }

        Cache::tags($tags)->flush();
    }

    protected function runAtomic(callable $callback)
    {
        $connections = collect([
            (new Account)->getConnectionName(),
            $this->connections->resolveTransactionDataConnection(),
        ])->map(fn ($connection) => $connection ?: DB::getDefaultConnection())
            ->unique()
            ->values();

        foreach ($connections as $connection) {
            DB::connection($connection)->beginTransaction();
        }

        try {
            $result = $callback();

            foreach ($connections as $connection) {
                DB::connection($connection)->commit();
            }

            return $result;
        } catch (Throwable $e) {
            foreach ($connections->reverse() as $connection) {
                $db = DB::connection($connection);

                while ($db->transactionLevel() > 0) {
                    $db->rollBack();
                }
            }

            throw $e;
        }
    }
}
