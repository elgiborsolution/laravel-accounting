<?php

namespace ESolution\LaravelAccounting\Traits;

use ESolution\LaravelAccounting\Support\AccountingConnectionResolver;
use Illuminate\Support\Facades\DB;

/**
 * For migrations in database/migrations/accounting that own tables on the master connection
 * (accounting.master_data.connection) rather than on a tenant transaction connection.
 *
 * Every package migration lives in one auto-loaded folder, so the same file also runs during
 * tenant migrations. The migrator makes the run's connection the default (`migrate --database`,
 * and tenancy bootstrappers switch it per tenant), so a central migration acts only when that
 * default is the configured master connection. Tenant runs never create, recreate or roll back
 * central tables. When no master connection is configured the migration is a no-op.
 */
trait HandlesCentralMigration
{
    /** The master connection when this run targets it; null means skip. */
    protected function centralConnection(): ?string
    {
        $name = app(AccountingConnectionResolver::class)->masterConnectionName();
        if ($name === null || ! config('database.connections.'.$name)) {
            return null;
        }

        return DB::getDefaultConnection() === $name ? $name : null;
    }
}
