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

    /*
    |--------------------------------------------------------------------------
    | Fiscal Settings
    |--------------------------------------------------------------------------
    |
    | Configurations related to fiscal periods.
    |
    */
    'fiscal' => [
        'start_month' => 1, // January
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
];
