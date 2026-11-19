# Phase 3 — Accounting & Financial Management: Implementation Plan

Status: **IMPLEMENTED** (approved 2026-11-17 with the recommended defaults). See docs/ARCHITECTURE.md §8.

## 1. What the inspection of Phases 1–2 found

| Existing structure | What accounting can use from it |
|---|---|
| `sales` / `sale_items` | Revenue, discounts and VAT per line. Each line has a `unit_cost` snapshot, so COGS can be posted exactly. The `SaleCompleted` and `SaleCancelled` events already fire after commit. |
| `sale_returns` / `sale_return_items` | Credit amount, VAT share and disposition (restock / write-off / supplier return). `SaleReturnCompleted` fires after commit. |
| `payments` + `payment_allocations` | One table for every receipt, refund and supplier payment, with method, branch, user and an idempotency UUID. **Accounting will reuse these rows and never duplicate them.** |
| `purchase_orders` / items | Net cost, VAT and the receive event. The supplier invoice number is stored on the order. |
| `inventory_transactions` | Every stock movement, with its unit cost. Adjustments, write-offs and transfers can be valued from it. |
| `tax_rates`, `settings` | Configurable VAT already exists; inclusive and exclusive pricing are both supported. |
| Money | Integer minor units everywhere. The ledger will use the same convention. |

**Gaps to close:**

1. Payments don't record *which* cash drawer or bank account the money went to.
2. There are no domain events for purchase receipts, payments or stock adjustments.
3. There is no expense, payroll or bank model.

## 2. Accounting architecture

**Double-entry general ledger. Every financial report is derived from posted journal lines.**

```
accounts                (chart of accounts: code, name_ar, name_en, type, parent_id, is_postable, is_active, normal_balance)
accounting_periods      (fiscal year/month, status: open | closed, closed_by/at)
journal_entries         (number, entry_date, branch_id, source_type/source_id, source_event, status: draft|posted|reversed,
                         reversal_of_id, memo, created_by, posted_by/at)
                         UNIQUE(source_type, source_id, source_event)   ← idempotent posting
journal_lines           (journal_entry_id, account_id, branch_id, debit, credit, party_type/party_id, description)
account_mappings        (key → account_id, optional branch_id/payment method) e.g. sales.revenue, vat.output, cash.register:{id}
```

**Posting rules, enforced in one `LedgerService::post()`:**

* The entry must balance: Σ debit = Σ credit, every line has exactly one non-zero side, and there are at least 2 lines. Otherwise it is rejected.
* The target period must be **open**, and only postable (leaf) accounts can be used.
* Posted entries are immutable. A correction is a new **reversal entry** (`reversal_of_id`) that swaps debits and credits; nothing is ever deleted.
* Idempotent: a unique `(source_type, source_id, source_event)` key means the same event can never post twice.
* Draft and cancelled documents are never posted. A cancellation posts a reversal of the original entry.
* The branch dimension sits on every line, which enables per-branch P&L and balance sheets as well as company-wide totals.

**Event → journal mapping (perpetual inventory, cost at the snapshot price).** Each step below is one journal entry.

| Event | Debit | Credit |
|---|---|---|
| Sale completed, step 1 (revenue) | Accounts Receivable (customer) | Sales Revenue (net of discount), VAT Output |
| Sale completed, step 2 (cost) | COGS | Inventory |
| Customer payment received | Cash register X / Bank Y / Card clearing | Accounts Receivable |
| Sale return completed | Sales Returns, VAT Output | Accounts Receivable |
| Return restocked | Inventory | COGS |
| Return written off | Inventory write-off expense | COGS |
| Refund paid | Accounts Receivable | Cash / Bank |
| Store-credit exchange | none: the out and in movements on AR cancel each other | |
| Sale cancelled | Reversal of the sale entries | |
| Purchase received | Inventory, VAT Input | Accounts Payable (supplier) |
| Supplier payment | Accounts Payable | Cash / Bank |
| Stock adjustment − / + | Inventory shrinkage expense / Inventory | Inventory / Inventory gain |
| Expense approved and paid | Expense category account, VAT Input | Cash / Bank / Accrued expenses |
| Cash deposit to bank | Bank | Cash register |
| Payroll paid | Salaries, Bonuses | Cash / Bank, Deductions payable, Employee advances |
| Employee advance | Employee advances (asset) | Cash / Bank |

Transfers between branches are not posted by default: stock stays at the same value inside one company. If you want inter-branch accounts, that can be switched on through a mapping.

**Worked example.** One sale at branch B01 for 1,150.00 SAR, VAT-inclusive 15%, cost 400.00, paid in cash. Each journal entry must balance on its own:

| Step | Account | Debit | Credit |
|---|---|---|---|
| Sale (revenue) | 1130 AR | 1,150.00 | |
| | 4110 Sales | | 1,000.00 |
| | 2140 VAT Output | | 150.00 |
| Sale (cost) | 5100 COGS | 400.00 | |
| | 1140 Inventory | | 400.00 |
| Payment | 1111 Cash – B01 Register 1 | 1,150.00 | |
| | 1130 AR | | 1,150.00 |

## 3. Proposed chart of accounts (configurable seed, editable in UI)

```
1000 Assets
  1100 Current assets
    1110 Cash on hand            → 1111.. one per cash register (auto-created per branch register)
    1120 Bank accounts           → 1121.. one per bank account
    1125 Card / POS clearing
    1130 Accounts receivable
    1140 Inventory
    1150 VAT input (recoverable)
    1160 Employee advances
    1170 Prepaid expenses
  1200 Non-current assets
    1210 Furniture & equipment   1220 Accumulated depreciation
2000 Liabilities
  2100 Current liabilities
    2110 Accounts payable
    2120 Customer advances / store credit
    2130 Accrued expenses
    2140 VAT output (payable)
    2150 Salaries payable / deductions payable
3000 Equity
  3100 Owner's capital   3200 Retained earnings   3300 Current year earnings (computed)
4000 Revenue
  4100 Sales revenue      4110 Sales (by product type optional)   4120 Sales returns (contra)   4130 Sales discounts (contra)
  4200 Other income
5000 Cost of goods sold
  5100 COGS   5200 Inventory shrinkage / write-offs   5300 Inventory count gains (contra)
6000 Operating expenses
  6100 Rent  6200 Salaries & wages  6210 Bonuses  6300 Electricity  6400 Internet & telecom  6500 Maintenance
  6600 Transportation  6700 Marketing  6800 Office expenses  6900 Other expenses  6950 Bank charges
```

* Every account has an Arabic and an English name and description, a parent and child structure, can be activated or deactivated, and has a **normal balance** (debit or credit).
* Accounts that already have postings cannot be deleted, only deactivated.
* The application logic refers to **mapping keys** (`sales.revenue`, `vat.output`…), never to account codes, so your accountant can restructure the chart freely.

## 4. Modules and screens

1. **Chart of accounts:** tree view, create/edit, activate/deactivate, live balance per account and branch.
2. **Cash management:**
   * Cash registers per branch, and cashier shifts: opening float → sales → closing count → variance posted to "cash over/short".
   * Deposits, withdrawals, transfers (register↔register, register→bank), daily reconciliation and movement history.
3. **Banks:** bank accounts, deposits, withdrawals, transfers between banks, payment references, statement import-free reconciliation (tick matched lines, then lock).
4. **Expenses:**
   * Categories map to 6xxx accounts. Each expense records branch, date, payment method/account, description and receipt attachments (private disk).
   * Workflow: draft → submitted → approved/rejected → posted.
5. **Revenue tracking:** from posted entries only. Sales revenue plus other income, by branch, day, month and year.
6. **Receivables:** customer balances read from the AR control account with party tagging. Aging and overdue reports, collections (reusing Phase 2 payments).
7. **Payables:** supplier balances from AP, outstanding invoices, payment history, aging (reusing Phase 2 payments).
8. **Payroll (financial part):**
   * `employees` (linked to `users` when the person has a login), salary records, monthly payroll runs (salary + bonuses − deductions − advance recovery), advances and payment history.
   * Restricted by a dedicated `payroll.view` permission.
   * **The Phase 4–7 HR module will extend this same `employees` table, not duplicate it.**
9. **Reports:** trial balance, general ledger, journal, account statement, income statement (P&L), balance sheet and cash-flow statement (indirect method from ledger movements). Each is filterable by period and branch, with monthly and annual comparisons, and exportable to PDF/print.
10. **Tax:** VAT report (output − input) per period, plus tax amounts per invoice. Tax rates and tax accounts are configurable.
11. **Period closing:** close and lock a month or year; the year-end close moves P&L into retained earnings. Needs the `accounting.close-period` permission; reopening needs a separate permission and is audited.
12. **Financial dashboard:** revenue, expenses, gross and net profit, cash and bank balances, receivables, payables, branch performance. Filters by date and branch.

## 5. Database changes (all additive, no destructive changes)

* New tables: `accounts`, `account_mappings`, `accounting_periods`, `journal_entries`, `journal_lines`, `cash_registers`, `cash_shifts`, `cash_movements`, `bank_accounts`, `bank_transactions`, `bank_reconciliations`, `expense_categories`, `expenses`, `attachments`, `employees`, `payroll_runs`, `payroll_items`, `employee_advances`.
* New **nullable** columns on `payments`: `cash_register_id`, `cash_shift_id`, `bank_account_id`. These link each payment to where the money actually went. Existing rows stay valid.
* New domain events: `PurchaseReceived`, `PaymentRecorded`, `InventoryAdjusted`.
* A backfill command `accounting:post-history` posts all existing Phase 2 documents. It is idempotent and can be run again safely.

## 6. Permissions (added to the existing RBAC)

`accounting.view`, `accounting.journal.create`, `accounting.journal.post`, `accounting.accounts.manage`, `accounting.close-period`, `accounting.reopen-period`, `cash.manage`, `cash.shift`, `bank.manage`, `bank.reconcile`, `expenses.create`, `expenses.approve`, `payroll.view`, `payroll.manage`, `reports.statements`.

All are enforced in services, scoped by branch, and every posting, reversal and closing is audited.

## 7. Roadmap (each step ends with green tests)

1. Ledger core: accounts, mappings, periods, the `LedgerService` (balanced / idempotent / reversal / closed-period rules) and the chart-of-accounts seeder and UI.
2. Automatic posting of sales, returns, cancellations, payments and purchases. Add the new events, then backfill.
3. Cash registers and shifts (with the POS link), and bank accounts and reconciliation.
4. Expenses with the approval workflow and secure attachments.
5. Payroll (financial part).
6. Reports: trial balance, ledger, P&L, balance sheet, cash flow, VAT, aging. Then the financial dashboard.
7. Period closing and year-end.
8. Security review, then the full regression run of Phases 1–3.

**Tests to be written:**

* Every posted entry balances.
* Sale, purchase, payment and expense postings.
* Duplicate postings are impossible.
* Reversals are correct.
* Closed periods reject postings.
* Cash and bank reconciliation.
* Branch isolation of financial data.
* P&L figures computed from a known scenario.
* Trial balance always nets to zero.

## 8. Decisions needed before implementation

1. **Chart of accounts:** use the proposed one (GCC/SOCPA-style), or will your accountant provide one?
2. **Cash shifts:** must cashiers open a shift before taking cash at the POS (recommended for daily reconciliation)?
3. **Fiscal year:** does it start in January?
4. **VAT jurisdiction and currency:** KSA, 15%, SAR? Are you VAT-registered (does the invoice need the ZATCA QR / e-invoicing)? ZATCA Phase 2 integration would be its own project.
5. **Card payments:** should they post to a "card clearing" account that is later settled to the bank (recommended), or straight to the bank?
6. **History:** should Phase 2 transactions already entered be backfilled into the ledger, or should the ledger start from an opening-balance date?
