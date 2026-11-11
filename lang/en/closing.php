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

    // Global monthly-reopening outcomes (reopenMonthGlobally()). Codes missing here fall back to errors.*.
    'reopen' => [
        'period_not_found' => [
            'message' => 'The :period fiscal period does not exist for this company, so there is nothing to reopen.',
            'action' => 'Check the selected year and month. Reference: :reference.',
        ],
        'later_month_closed' => [
            'message' => ':period cannot be reopened because :later is still closed.',
            'action' => 'Reopen the later months first, latest first, then retry. Reference: :reference.',
        ],
        'cutoff_active' => [
            'message' => ':period was reopened, but the closing cutoff through :date still blocks corrections dated on or before it.',
            'action' => 'Move the cutoff back with reopenCutoff() and a reason before posting those corrections.',
        ],
        'database_error' => [
            'message' => 'The accounting database for this company could not be updated while reopening :period.',
            'action' => 'Retry later. If it keeps happening, contact an administrator with reference :reference.',
        ],
        'tenant_unavailable' => [
            'message' => 'This company\'s accounting data could not be opened to reopen :period.',
            'action' => 'Check that the company still exists and its database is available, then retry. Reference: :reference.',
        ],
        'configuration_error' => [
            'message' => 'The closing settings are invalid or incomplete, so :period could not be reopened.',
            'action' => 'Ask an administrator to review the accounting closing settings. Reference: :reference.',
        ],
        'unexpected_error' => [
            'message' => ':period could not be reopened because of an unexpected problem.',
            'action' => 'Retry later. If it keeps happening, contact an administrator with reference :reference.',
        ],
    ],
];
