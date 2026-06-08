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
