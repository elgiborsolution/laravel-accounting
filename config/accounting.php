<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Accounting Tables Prefix
    |--------------------------------------------------------------------------
    |
    | This value will be used as a prefix for all accounting tables.
    |
    */
    'table_prefix' => 'acc_',

    /*
    |--------------------------------------------------------------------------
    | Default Master Language
    |--------------------------------------------------------------------------
    |
    | Controls which localized master data seed set is used by the package.
    | Supported values: id, en
    |
    */
    'default_language' => env('ACCOUNTING_DEFAULT_LANGUAGE', 'id'),

    /*
    |--------------------------------------------------------------------------
    | Master Data Connection
    |--------------------------------------------------------------------------
    |
    | When enabled, master data tables will use the configured shared
    | connection instead of the application's current default connection.
    |
    */
    'master_data' => [
        'use_shared_database' => env('ACCOUNTING_USE_SHARED_DATABASE', false),
        'connection' => env('ACCOUNTING_MASTER_CONNECTION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Journal Settings
    |--------------------------------------------------------------------------
    |
    | Configurations related to journal entries.
    |
    */
    'journal' => [
        'auto_post' => true,
        'number_format' => 'JV/{YEAR}/{MONTH}/{SEQ}',
    ],

    // Stable code => summary component. Account overrides win over the nearest
    // mapped ancestor category. Generic tax account 5210 remains operating
    // expense unless the host explicitly maps it to income_tax.
    'profit_loss_summary' => [
        'account_mappings' => [
            '5210' => 'operating_expenses',
            '5601' => 'sales_discounts',
            '5701' => 'sales_returns',
        ],
        'category_mappings' => [
            'SALES_REVENUE' => 'sales_revenue',
            'SERVICE_REVENUE' => 'sales_revenue',
            'COST_OF_GOODS_SOLD' => 'cogs',
            'OPERATING_EXPENSE' => 'operating_expenses',
            'OTHER_REVENUE' => 'non_operating_income',
            'OTHER_EXPENSE' => 'non_operating_expenses',
        ],
    ],

    // Journals treated as profit transfers (e.g. revenue/expense -> income summary -> retained
    // earnings) and excluded from balance-sheet profit details, alongside opening-balance journals.
    // closing_service_codes match the journal's service (journalByMapping); closing_source_types
    // match journals.source_type (e.g. journalManual(['source_type' => 'MONTH_END_CLOSING', ...])).
    // Reversals copy both markers. Use [] to disable either list.
    'profit_loss_detail' => [
        'closing_service_codes' => ['MONTH_END_CLOSING', 'YEAR_END_CLOSING'],
        'closing_source_types' => ['MONTH_END_CLOSING', 'YEAR_END_CLOSING'],
    ],

    // Balance-sheet dashboard classification: stable code => 'current' | 'non_current' for
    // ASSET/LIABILITY accounts (current ratio). Account overrides win over the nearest mapped
    // ancestor category; unmapped accounts are non-current. Merged over these defaults.
    'balance_sheet_summary' => [
        'account_mappings' => [],
        'category_mappings' => [
            'CURRENT_ASSET' => 'current',
            'CURRENT_LIABILITY' => 'current',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fiscal Settings
    |--------------------------------------------------------------------------
    |
    | Configurations related to fiscal periods.
    |
    */
    'fiscal' => [
        // 1-12, January by default. Balance-sheet summaries use it for current-year profit/loss.
        'start_month' => 1,
    ],

    // Operational cutoff dates live in the database, never in environment config.
    'closing' => [
        // Earliest execution day for closeMonth(), bulk and shared month closing: null/empty keeps
        // prior unrestricted timing; an integer 1–31 (clamped to the target month's last day); or
        // 'month_end' for the target month's last day. Eligibility starts at midnight on that day.
        'closing_day' => env('ACCOUNTING_CLOSING_DAY'),

        // Which month closing_day belongs to: 'next' (default, the month after the selected month)
        // or 'current' (the month being closed, e.g. month_end allows November from November 30).
        // Never changes the selected month's transaction boundaries.
        'closing_day_month' => env('ACCOUNTING_CLOSING_DAY_MONTH', 'next'),

        // Enables persisted inclusive trx_date locks and cutoff close/reopen APIs. Default false.
        // Monthly fiscal-period closing remains available when false.
        'cutoff_enabled' => env('ACCOUNTING_CLOSING_CUTOFF_ENABLED', false),

        // Cutoff storage policy when cutoff_enabled is true. Accepted: per_tenant (default) or shared.
        // per_tenant stores one cutoff and audit history in each transaction database.
        // shared stores one authoritative cutoff on master_data.connection (ACCOUNTING_MASTER_CONNECTION),
        // independent of master_data.use_shared_database; that connection must be separate from every
        // tenant transaction DB. Participating tenants come from the tenantIds argument of the first
        // shared close and are persisted centrally for later calls.
        'cutoff_mode' => env('ACCOUNTING_CLOSING_CUTOFF_MODE', 'per_tenant'),

        // Global monthly closing (ClosingService::closeMonthGlobally(), accounting:closing-close-month).
        // Tracking tables live on master_data.connection; one queued job closes each tenant.
        // queue_connection/queue: null uses the host defaults. lease_seconds: how long a worker's claim
        // on a tenant/period lasts before a crashed or stuck attempt may be recovered (minimum 60).
        'global' => [
            'queue_connection' => env('ACCOUNTING_CLOSING_QUEUE_CONNECTION'),
            'queue' => env('ACCOUNTING_CLOSING_QUEUE'),
            'lease_seconds' => env('ACCOUNTING_CLOSING_LEASE_SECONDS', 900),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Central Reporting
    |--------------------------------------------------------------------------
    |
    | Journals and closing stay in each tenant database. With change_log_enabled,
    | journal/closing/cutoff writes also record their changed item in the tenant's
    | reporting change log (same transaction). The accounting:reporting-* commands
    | copy tenant data into central tables on master_data.connection
    | (ACCOUNTING_MASTER_CONNECTION); the host registers their schedule.
    | Supported only with shared master data (ACCOUNTING_USE_SHARED_DATABASE=true):
    | accounts, categories and services are read from the master tables, never
    | copied. Other configurations are rejected by the migrations, commands and
    | central reads, and change_log_enabled is ignored for them.
    |
    | read_source: 'live' (default) keeps tenant-list reports on tenant databases;
    | 'central' answers tenant-list balance-sheet/profit-loss reports, summaries,
    | ranges and profit details from the central tables, without initializing
    | tenants, and rejects them unless every selected tenant is backfilled,
    | not failed or drifted, and synced within max_lag_seconds.
    |
    */
    'reporting' => [
        'read_source' => env('ACCOUNTING_REPORTING_READ_SOURCE', 'live'),
        'change_log_enabled' => env('ACCOUNTING_REPORTING_CHANGE_LOG_ENABLED', false),
        'max_lag_seconds' => env('ACCOUNTING_REPORTING_MAX_LAG_SECONDS', 900),
        'batch_size' => 500,
        'lock_seconds' => 600,
        'retry_base_seconds' => 60,
        'retry_max_seconds' => 3600,
        'prune_after_days' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Settings
    |--------------------------------------------------------------------------
    |
    | Configurations related to package routes.
    |
    */
    'route' => [
        'prefix' => 'api/accounting',
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | API Hooks
    |--------------------------------------------------------------------------
    |
    | Hooks allow the host application to execute custom logic before and
    | after package API or service execution.
    |
    | before:
    | - Executed before the package runs the main business logic.
    | - Receives ESolution\LaravelAccounting\Support\ApiContext.
    | - Can inspect request, headers such as X-Tenant, authenticated user,
    |   and current payload.
    | - Can normalize or replace the payload through the context object.
    | - Set to null to disable.
    |
    | after:
    | - Executed after the package completes successfully.
    | - Receives the same ApiContext plus the current result.
    | - Can return the original result or a modified one.
    | - Useful for audit logs, response decoration, sync jobs, notifications,
    |   and cache invalidation.
    | - Set to null to disable.
    |
    | To register a custom hook, point before/after to a concrete class in the
    | host application, for example:
    |
    | 'before' => App\Accounting\Hooks\BeforeApiHook::class,
    | 'after' => App\Accounting\Hooks\AfterApiHook::class,
    |
    */
    'hooks' => [
        'before' => null,
        'after' => null,
    ],
];
