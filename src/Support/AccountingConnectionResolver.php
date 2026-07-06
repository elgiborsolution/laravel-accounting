<?php

namespace ESolution\LaravelAccounting\Support;

use Illuminate\Support\Facades\DB;

class AccountingConnectionResolver
{
    public function useSharedMasterDatabase(): bool
    {
        return (bool) config('accounting.master_data.use_shared_database', false);
    }

    public function masterConnectionName(): ?string
    {
        $connection = config('accounting.master_data.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function resolveMasterDataConnection(?string $explicitConnection = null): ?string
    {
        if ($explicitConnection !== null && $explicitConnection !== '') {
            return $explicitConnection;
        }

        if (! $this->useSharedMasterDatabase()) {
            return null;
        }

        return $this->masterConnectionName();
    }

    public function shouldCreateCrossConnectionForeignKeys(?string $currentConnection = null): bool
    {
        if (! $this->useSharedMasterDatabase()) {
            return true;
        }

        $currentConnection ??= DB::getDefaultConnection();

        return $this->masterConnectionName() === $currentConnection;
    }
}
