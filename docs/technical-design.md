# Technical Design

This document defines the normalized chart of accounts and reporting design used as the documentation baseline for the package.

## Design Goals

- More normalized data model
- Accounts act only as posting accounts
- Financial statement hierarchy lives fully in `acc_account_categories`
- Category structure can evolve without changing the chart of accounts codes
- Profit Loss, Balance Sheet, and Cash Flow grouping becomes easier to maintain
- Better long-term scalability for enterprise ERP data

## Core Schema

### `acc_account_categories` (tree)

Hierarchy exists only in the category table.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `BIGINT` | Primary key |
| `parent_id` | `BIGINT NULL` | Self reference to `acc_account_categories.id` |
| `type` | `VARCHAR` / enum-like | Default classification root: `ASSET`, `LIABILITY`, `EQUITY`, `REVENUE`, `EXPENSE` |
| `category_code` | `VARCHAR` | Unique category code |
| `category_name` | `VARCHAR` | Free and fully custom label |
| `sequence_no` | `INT` | Sibling ordering |
| `is_active` | `BOOLEAN` | Active flag |
| `created_at` | `TIMESTAMP` | Audit column |
| `updated_at` | `TIMESTAMP` | Audit column |

Rules:

- `parent_id` creates the report tree.
- `type` is limited to the default root classifications below.
- `category_name` is custom and can be changed without changing the root type.
- Every descendant branch inherits its report meaning from the category path, not from account nesting.

### Default root `type` values

- `ASSET`
- `LIABILITY`
- `EQUITY`
- `REVENUE`
- `EXPENSE`

### Example category tree

```text
ASSET
`-- Current Asset
    |-- Cash & Cash Equivalent
    |-- Account Receivable
    |-- Inventory
    `-- Prepaid Expense

ASSET
`-- Fixed Asset
    |-- Land
    |-- Building
    |-- Vehicle
    `-- Equipment

REVENUE
`-- Sales Revenue

EXPENSE
`-- Operating Expense
    |-- Salary Expense
    |-- Electricity Expense
    `-- Water Expense
```

### `acc_accounts` (leaf / posting accounts only)

Accounts no longer carry hierarchy metadata.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `BIGINT` | Primary key |
| `category_id` | `BIGINT` | Required FK to `acc_account_categories.id` |
| `code` | `VARCHAR` | Unique posting account code |
| `name` | `VARCHAR` | Posting account name |
| `description` | `TEXT NULL` | Optional notes or account description |
| `is_postable` | `BOOLEAN` | Posting flag |
| `is_active` | `BOOLEAN` | Active flag |
| `created_at` | `TIMESTAMP` | Audit column |
| `updated_at` | `TIMESTAMP` | Audit column |

Rules:

- Remove `parent_id` from `acc_accounts`.
- Remove `level` from `acc_accounts`.
- Every account must belong to exactly one category.
- `description` is optional and can be used for notes or operational context.
- Accounts never define report structure.
- Accounts are the leaves used by journals and balances.

### Example posting accounts

```text
ASSET
`-- Current Asset
    `-- Cash & Cash Equivalent
        |-- Kas
        |-- Bank BCA
        `-- Bank Mandiri

REVENUE
`-- Sales Revenue
    |-- Penjualan Retail
    `-- Penjualan Online
```

## Relationship Diagram

```text
acc_account_categories (Tree)
        |
        |-- parent_id -> acc_account_categories.id
        |
        `-- 1 : N
             |
             v
        acc_accounts
             |
             v
   acc_journal_entry_details
             |
             v
      acc_monthly_balances
             |
             v
      Financial Reports
```

## Reporting Design

All standard financial reports use `acc_account_categories` as the reporting hierarchy.

### General Ledger

- Source rows come from `acc_journal_entry_details`.
- The report is still filtered by posting account.
- Each account can show its category path for context.
- Category hierarchy is informational in the ledger view and structural in summary reports.

### Trial Balance

- Closing balances are stored per posting account in `acc_monthly_balances`.
- Each posting account balance is mapped to its `category_id`.
- Balances are rolled up recursively from the account's category to all ancestors.
- The displayed structure follows the category tree, not account hierarchy.

### Profit Loss

- Uses the `REVENUE` and `EXPENSE` roots.
- Totals are aggregated from posting accounts to descendant categories, then up to the root.
- Subtotals such as `Sales Revenue` or `Operating Expense` come from category nodes.

### Balance Sheet

- Uses the `ASSET`, `LIABILITY`, and `EQUITY` roots.
- Category subtotals are built by recursive roll-up from posting accounts.
- Presentation order follows `sequence_no` inside the category tree.

### Cash Flow

- Uses the same category tree as the master reporting hierarchy.
- Cash movement grouping is category-driven, not account-parent driven.
- Presentation views such as operating, investing, and financing should be assembled from category branches while keeping the category tree as the aggregation backbone.

## Aggregation Flow

```text
Posting account balances
  -> resolve `category_id`
  -> accumulate to the direct category
  -> roll up to each ancestor category
  -> sort by `sequence_no`
  -> render category totals
  -> optionally render posting accounts under terminal categories
```

## Example Report Shapes

### Trial Balance

```text
ASSET
`-- Current Asset                               175,000,000
    |-- Cash & Cash Equivalent                  150,000,000
    |   |-- Kas                                  10,000,000
    |   |-- Bank BCA                             90,000,000
    |   `-- Bank Mandiri                         50,000,000
    `-- Account Receivable                       25,000,000

REVENUE
`-- Sales Revenue                               250,000,000
    |-- Penjualan Retail                        180,000,000
    `-- Penjualan Online                         70,000,000
```

### Profit Loss

```text
REVENUE
`-- Sales Revenue                               250,000,000
    |-- Penjualan Retail                        180,000,000
    `-- Penjualan Online                         70,000,000

EXPENSE
`-- Operating Expense                            95,000,000
    |-- Salary Expense                           60,000,000
    |-- Electricity Expense                      20,000,000
    `-- Water Expense                            15,000,000

NET PROFIT                                      155,000,000
```

### Balance Sheet

```text
ASSET
`-- Current Asset                               175,000,000
`-- Fixed Asset                                 320,000,000

LIABILITY
`-- Current Liability                            85,000,000

EQUITY
`-- Owner Equity                                410,000,000
```

### Cash Flow

```text
Operating Activities
`-- REVENUE > Sales Revenue                     250,000,000
`-- EXPENSE > Operating Expense                 (95,000,000)

Investing Activities
`-- ASSET > Fixed Asset                         (20,000,000)

Financing Activities
`-- LIABILITY > Long Term Liability              40,000,000
```

## Why This Design Was Chosen

- More normalized because hierarchy is stored in one place only.
- Accounts become pure posting accounts, which reduces ambiguity.
- Report hierarchy is fully controlled by the category tree.
- New report branches can be added without restructuring account parents.
- Profit Loss, Balance Sheet, and Cash Flow grouping becomes easier to maintain over time.
- The model scales better for large ERP implementations and long historical datasets.
