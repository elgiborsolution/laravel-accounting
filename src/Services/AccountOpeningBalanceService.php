<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\JournalEntry;
use ESolution\LaravelAccounting\Support\AccountingConnectionResolver;
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
            $this->guardOpeningBalanceMutation($account, $attributes);

            $account->update($this->extractAccountAttributes($attributes));
            $account->refresh();

            $this->applyOpeningBalanceIfRequested($account, $attributes);

            return $account->fresh();
        });
    }

    protected function applyOpeningBalanceIfRequested(Account $account, array $attributes): void
    {
        $amount = $this->resolveOpeningBalanceAmount($attributes);

        if ($amount <= 0) {
            return;
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

    protected function guardOpeningBalanceMutation(Account $account, array $attributes): void
    {
        if (! $this->hasOpeningBalancePayload($attributes)) {
            return;
        }

        if (! $this->hasOpeningBalanceJournal($account)) {
            return;
        }

        throw ValidationException::withMessages([
            'opening_balance' => ['Opening balance has already been set for this account.'],
        ]);
    }

    protected function hasOpeningBalancePayload(array $attributes): bool
    {
        return array_key_exists('opening_balance', $attributes)
            || array_key_exists('opening_balance_date', $attributes);
    }

    protected function hasOpeningBalanceJournal(Account $account): bool
    {
        return JournalEntry::query()
            ->where('source_type', self::SOURCE_TYPE)
            ->where('source_id', $account->id)
            ->exists();
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
