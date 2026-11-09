<?php

// Readable global monthly-closing outcomes. :period is the localized month name and year;
// :reference matches the application log entry that holds the technical details.
return [
    'errors' => [
        'not_due' => [
            'message' => ':period cannot be closed before :date.',
            'action' => 'Wait until the closing date, or ask an administrator to review the closing day setting.',
        ],
        'reopened_period' => [
            'message' => ':period was reopened, so scheduled closing skipped it.',
            'action' => 'Close it manually once the corrections are finished.',
        ],
        'unbalanced_journals' => [
            'message' => 'Posted journals for :period are not balanced (debit :debit, credit :credit).',
            'action' => 'Correct the journals for this period, then retry.',
        ],
        'account_reference_mismatch' => [
            'message' => 'Account balances for :period could not be saved because the accounts in this company\'s database do not match the chart of accounts.',
            'action' => 'Ask an administrator to check the accounting master-data setting and migrations, then retry. Reference: :reference.',
        ],
        'database_error' => [
            'message' => 'The accounting database for this company could not be updated while closing :period.',
            'action' => 'Retry later. If it keeps happening, contact an administrator with reference :reference.',
        ],
        'tenant_unavailable' => [
            'message' => 'This company\'s accounting data could not be opened to close :period.',
            'action' => 'Check that the company still exists and its database is available, then retry. Reference: :reference.',
        ],
        'configuration_error' => [
            'message' => 'The closing settings are invalid or incomplete, so :period could not be closed.',
            'action' => 'Ask an administrator to review the accounting closing settings. Reference: :reference.',
        ],
        'unexpected_error' => [
            'message' => ':period could not be closed because of an unexpected problem.',
            'action' => 'Retry later. If it keeps happening, contact an administrator with reference :reference.',
        ],
    ],
];
