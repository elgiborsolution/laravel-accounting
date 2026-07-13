# Journal Engine

The journal engine is implemented primarily by `JournalService`, `JournalEntry`, and `JournalEntryDetail`.

## Journal States

The `acc_journal_entries.status` column supports:

- `draft`
- `posted`
- `reversed`

## Draft Journal

Draft journals are created by:

- `JournalService::journalByMapping()`

`journalByMapping()` creates a journal with `status = draft` and detail rows, then optionally auto-posts it if `accounting.journal.auto_post` is enabled.

`journalByMapping()` resolves master data first through repositories, so service and mapping lookup can target a shared master connection while the journal header and details stay on the active application or tenant connection.

## Post Journal

`JournalService::post($id)`:

- loads the journal
- checks fiscal period lock
- marks the journal as `posted`
- writes `posted_at`
- writes `posted_by`

If the journal is already posted, the method returns it without creating duplicate state.

## Reverse Journal

`JournalService::reverse($journalId, string $reason)`:

- loads the original journal with details
- validates the journal is posted
- rejects double reversal
- rejects reversal when the period is closed
- creates a brand-new journal row
- swaps debit and credit on each detail
- stores the original journal reference in `reversal_of_id`
- stores `reversal_reason`
- stores `reversed_at`
- marks the new journal as `is_reversal = true`
- posts the reversal journal immediately

### Reversal workflow

```text
Posted journal
  ↓
Call reverse()
  ↓
Create reversal journal
  ↓
Swap debit / credit on each detail
  ↓
Post reversal journal
  ↓
Leave both rows in the database
```

### Important audit rule

Posted journals are immutable in the model layer. The package does not provide a supported “edit posted journal” workflow.

## Cancel Journal

### Planned Feature

There is no implemented cancel endpoint or cancel service method in the current source tree.

If you need cancellation semantics, use a reversal journal instead and create a correcting journal afterward.

## Manual Journal

`JournalService::journalManual()` is the manual-entry workflow for balanced ad hoc journals.

It:

- resolves account IDs from `account_id` or `account_code`
- validates debit/credit types
- checks balance
- validates account activity and postable status
- checks the fiscal period lock
- writes the journal header
- writes detail rows
- posts the journal immediately

## State Transitions

```text
draft -> posted
posted -> reversal created (original remains posted)
posted -> reversed status exists in enum, but is not currently assigned by the service layer
```

## Immutability

`JournalEntry` blocks updates after a journal has been posted. This is the primary enforcement point for the accounting rule that posted journals must never be edited.

## Audit Trail

The journal model now stores the reversal metadata needed for an audit trail:

- `reversal_of_id`
- `reversal_reason`
- `reversed_at`
- `is_reversal`

