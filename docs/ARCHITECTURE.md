# Eyewear ERP — Architecture (Phases 1–7, HR, eyewear services)

## 1. Stack

| Layer | Choice |
|---|---|
| Runtime | PHP 8.3+ (tested on 8.3.36), Laravel 13.42 |
| UI | Livewire 4 (class components), Blade, Tailwind CSS 4 (built with Vite, output committed to `public/build` on deploy) |
| Database | One central MySQL 8 / MariaDB 10.6+ database for every branch |
| Auth / RBAC | Laravel session auth + `spatie/laravel-permission` |
| Documents | `mpdf/mpdf` (Arabic shaping + RTL PDF), `picqer/php-barcode-generator` (EAN-13 / Code128 SVG), `chillerlan/php-qrcode` (SVG) |

All of these are pure PHP and work on shared hosting with no binaries, workers or WebSockets.

## 2. Modular monolith layout

```
app/
  Models/User.php                 Auth user (+ branch assignments, roles)
  Livewire/                       Login, Dashboard, shared concerns
  Http/Middleware/                SetLocale, EnsureUserIsActive, SecurityHeaders, TrustConfiguredProxies
  Modules/
    Core/        Money, Settings, AuditLogger, DocumentNumberGenerator, TaxCalculator, PdfRenderer, Permissions, settings & audit UI
    Branches/    Branch model, BranchAccess (branch isolation), branch UI
    Users/       User management UI
    Catalog/     Product, Category, Brand, ProductImage, ProductService, CodeGenerator (barcode/QR), labels
    Inventory/   Inventory, InventoryTransaction, StockTransfer(+Item), InventoryService, StockTransferService
    Suppliers/   Supplier, SupplierLedger, supplier UI/statement
    Customers/   Customer, CustomerLedger, customer UI/statement
    Purchasing/  PurchaseOrder(+Item), PurchaseService, PO PDF
    Sales/       Sale(+Item), SaleReturn(+Item), SaleService, ReturnService, SalesReport, POS, documents, domain events
    Payments/    Payment, PaymentAllocation, PaymentService, payments register
    Accounting/  Accounts, journal, LedgerService, AccountingPoster, periods, financial reports (Phase 3)
    Treasury/    Cash registers & shifts, bank accounts, reconciliation (Phase 3)
    Expenses/    Expenses, private attachments (Phase 3)
    Payroll/     Employee (shared with HR), advances, payroll runs (Phase 3)
    Reports/     ReportEngine, ReportExporter, InsightsEngine, AiNarrator, dashboards (Phase 4)
    Imports/     Excel/CSV/Google Sheets import, SafeUrlFetcher (Phase 5)
    Offline/     PWA POS devices, sync engine, conflicts (Phase 6)
    HR/          Attendance, leave, salary history, reviews, performance (HR module)
    Optical/     Prescriptions, warranties & claims, repairs, loyalty (eyewear module)
```

Rules each module follows:

* **Services own the writes.** Livewire components validate the input, then call a service. Each service checks permissions (`Gate::authorize`) and branch access itself, so no write path depends on the UI.
* **Models protect computed state.** Quantities, totals and statuses are not mass-assignable. Ledgers (inventory transactions, audit logs, payments) throw an exception on update or delete.
* **Domain events** (`SaleCompleted`, `SaleCancelled`, `SaleReturnCompleted`, …) are dispatched **inside** the business transaction. Accounting entries, warranties and loyalty postings therefore commit or roll back together with the sale.

## 3. Database (single schema)

Money is stored as **integer minor units** (`BIGINT`; e.g. 549.50 SAR = `54950`). Calculations never use floats: `App\Modules\Core\Support\Money` parses decimal strings and uses integer rounding (`divRound`, `allocate` with the largest-remainder method). The number of decimals comes from `ERP_CURRENCY_DECIMALS` (use 3 for KWD/JOD/BHD/OMR).

### Phase 1 tables

| Table | Purpose / key constraints |
|---|---|
| `users` | `email` unique, `is_active`, `locale`, `default_branch_id` → branches |
| `branches` | `code` unique, `is_active` |
| `branch_user` | user↔branch assignment, unique(branch_id, user_id) |
| `roles`, `permissions`, `model_has_*`, `role_has_permissions` | spatie RBAC |
| `categories` | self-referencing `parent_id`, `code` unique |
| `brands` | `name` unique |
| `suppliers` | `code` unique |
| `products` | **`sku` unique, `barcode` unique, `qr_code` unique**, FKs to category/brand/supplier/tax_rate, eyewear fields (`product_type`, `frame_type`, `frame_material`, `lens_type`, `gender`, `specifications` JSON), `purchase_price`, `selling_price`, `cost_price` |
| `product_images` | FK product (cascade), random file names on the `public` disk |
| `inventory` | **unique(branch_id, product_id)**, `quantity` (signed), `reserved_quantity`, `min_stock_level` |
| `inventory_transactions` | immutable stock ledger: type, signed quantity, balance_before/after, unit_cost, reason, polymorphic `reference`; **unique(reference_type, reference_id, type)** so a document line cannot move stock twice |
| `stock_transfers`, `stock_transfer_items` | status workflow, one user/timestamp column per transition |
| `audit_logs` | append-only (user, branch, event, old/new values, IP, user agent) |
| `settings`, `tax_rates`, `document_sequences` | business settings, configurable VAT, locked number sequences |
| `import_logs` | prepared for Phase 5 (xlsx/csv/Google Sheets, column mapping, duplicate strategy, counters, error report). **Not active yet** |

`product_variants` was deliberately **not** created. In eyewear retail every colour/size combination already has its own SKU and barcode, so each one is a `products` row. They are grouped by `model` and `brand_id`. This keeps stock, pricing and labels one-to-one with what is physically scanned.

### Phase 2 tables

| Table | Purpose / key constraints |
|---|---|
| `customers` | `phone` unique (nullable). A walk-in sale has `customer_id = NULL`, controlled by the `walk_in_customer_enabled` setting |
| `purchase_orders` | `number` unique, unique(supplier_id, supplier_invoice_number), status, payment_status, totals, paid_amount |
| `purchase_order_items` | qty, unit_cost, discount, tax rate/amount, line_total |
| `sales` | **`uuid` unique (idempotency key)**, **`invoice_number` unique**, branch, cashier, customer, status, payment_status, return_status, subtotal/discount/tax/total, paid/returned/refunded |
| `sale_items` | product name/SKU snapshot, unit_price, discount, tax, line_total, **unit_cost snapshot (COGS)**, returned_quantity |
| `sale_returns`, `sale_return_items` | separate documents linked to the sale; condition + disposition per line; refunded and exchange-credit amounts |
| `payments` | **`uuid` unique**, `number` unique, direction in/out, party (customer/supplier), method, amount, tendered/change, reference |
| `payment_allocations` | payment → sale / purchase order (polymorphic), unique(payment, document) |

### Relationships (summary)

```
Branch 1─* Inventory *─1 Product *─1 Category / Brand / Supplier / TaxRate
Branch 1─* InventoryTransaction *─1 Product        (reference → SaleItem | PurchaseOrderItem | StockTransferItem | SaleReturnItem)
User *─* Branch (branch_user)                      User *─* Role *─* Permission
StockTransfer 1─* StockTransferItem                 from_branch / to_branch → Branch
Supplier 1─* PurchaseOrder 1─* PurchaseOrderItem
Customer 1─* Sale 1─* SaleItem;  Sale 1─* SaleReturn 1─* SaleReturnItem → SaleItem
Payment 1─* PaymentAllocation → Sale | PurchaseOrder ;  Payment → Customer | Supplier, Branch, User, SaleReturn
```

## 4. Inventory engine

`InventoryService::move()` is the **only** code that changes `inventory.quantity` or `reserved_quantity`:

1. Run inside a DB transaction.
2. Lock the `(branch, product)` row with `SELECT … FOR UPDATE` (the row is created first if missing). Multi-line documents lock their rows in product-id order, so two documents can never deadlock each other.
3. Check that the movement would not go below **available** stock (on hand minus reserved). Negative stock needs both the `inventory.allow-negative` permission and an explicit flag.
4. Update the quantity and write an `InventoryTransaction` in the same transaction.

Every movement type: opening, receipt, adjustment ±, transfer out/in, purchase receipt, sale, sale cancellation, customer return.

Transfers: `pending → approved` (stock reserved at source) `→ dispatched` (transfer_out) `→ received` (transfer_in at destination). Cancelling is only possible while pending or approved, and it releases the reservation. Each transition locks the transfer row and checks the current status.

## 5. Sales, purchasing and payments

* **Prices come from the server.** The POS sends product IDs, quantities and discounts; prices, tax and totals are recalculated by `TaxCalculator` on the server.
* **Discounts**: any discount needs `sales.discount`. A discount above `max_discount_percent` also needs `sales.discount-unlimited`.
* **Tax**: off by default, configurable on the Settings page (VAT rates, default rate, prices include or exclude tax). Purchase prices are entered excluding tax.
* **Duplicate invoices are impossible.** The POS generates a UUID per cart:
  * replaying the same UUID returns the original sale;
  * a race between two requests hits the unique index and also returns the original;
  * each payment line gets a deterministic UUIDv5 derived from the sale UUID, so payments cannot be duplicated either;
  * invoice numbers come from a locked `document_sequences` row, per branch and year (`INV-B01-2026-000123`).
* **Duplicate payments are impossible.** Every payment form carries a UUID, so a double-click or retry returns the first payment. Allocations are checked against the live balance of a locked document.
* **Balances are derived, never stored separately:**
  * sale balance = `total − returned − paid + refunded`
  * customer balance = the sum of that over open sales
  * supplier balance = Σ received purchase-order totals − Σ supplier payments
* **Costing:** `weighted_average` (default) or `last_purchase`, applied when goods are received. Each sale line keeps a cost snapshot for COGS.
* **Returns:** a return is created as *pending*, which reserves the sale-line quantities.
  * **Completing** it confirms the physical inspection. Lines marked *restock* go back into inventory, and the credit is applied to the sale.
  * The credit can then be **refunded** (cash/card/transfer) or used as **store credit** in the POS (an exchange): money moves out of the old sale and into the new one with no cash involved.
  * The original sale is never deleted.
* **Cancellation:** only for sales with no returns. Stock is reversed with `sale_cancel` movements, the net paid amount is refunded, and the record stays with status `cancelled`.

## 6. Security

| Control | Implementation |
|---|---|
| Authentication | Session login, bcrypt passwords (min 10 chars, mixed case + digits), 5 attempts/min per email+IP, session regenerated on login, inactive users blocked and logged out |
| Authorization | ~50 permissions in `App\Modules\Core\Permissions`, 6 roles (super-admin bypass via `Gate::before`), checked in services **and** in components/controllers |
| Branch isolation | `BranchAccess` checks every branch-scoped query and write; the selected branch in the session is re-validated on each use; combined views need `branches.access-all` |
| Tampering | Livewire IDs that matter are `#[Locked]`; prices/totals recalculated on the server; no mass assignment of quantities, totals or statuses |
| CSRF / XSS / SQLi | Laravel CSRF middleware, Blade escaping (only server-generated SVG is output raw), Eloquent/query bindings everywhere |
| Uploads | Images only (jpg/png/webp, ≤2 MB, ≤6000 px); SVG is rejected; random file names; per-product folder |
| Headers | `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, HSTS on HTTPS |
| Rate limiting | Login; document/PDF endpoints (30/min per user) |
| Audit | Immutable `audit_logs` for auth, catalog, stock settings, transfers, purchases, sales, returns, payments, settings, users |
| Proxies | Forwarded headers are trusted only from `TRUSTED_PROXIES` |

## 7. Integration points prepared for later phases

* **Phase 3 (accounting):** subscribe to `SaleCompleted`, `SaleCancelled` and `SaleReturnCompleted`. Add equivalent events for purchase receipts and payments. Sale lines already carry the cost snapshot needed for COGS, and payments carry method and branch.
* **Phase 5 (imports):** `import_logs` exists, and `ProductService::create/update` is the single validated entry point the importer will call.
* **Phase 6 (offline POS):** sales already use client-generated UUIDs and are idempotent on the server, which is the core of offline sync.

---

## 8. Phase 3 — Accounting (implemented)

### Modules
```
app/Modules/
  Accounting/  Account, AccountMapping, AccountingPeriod, JournalEntry, JournalLine,
               LedgerService (only writer of entries), AccountingPoster (event → entry), PeriodService,
               FinancialReports, AccountResolver; chart of accounts, journal, periods and all report screens
  Treasury/    CashRegister, CashShift, BankAccount, TreasuryMovement, BankReconciliation, TreasuryService
  Expenses/    ExpenseCategory, Expense, ExpenseService (draft → submitted → approved/rejected), private attachments
  Payroll/     Employee, EmployeeAdvance, PayrollRun, PayrollItem, PayrollService
```

### Ledger rules (enforced in `LedgerService`)
* An entry is posted only if Σ debit = Σ credit, it has at least 2 lines, each line has exactly one non-zero side, and it uses only active postable (leaf) accounts.
* **Idempotent:** UNIQUE(source_type, source_id, source_event). Replaying an event returns the existing entry.
* **Closed periods** reject postings. A year-end close moves P&L balances to retained earnings per branch.
* **Immutable:** posted entries and lines throw an exception on update or delete. Corrections are reversal entries (`reversal_of_id` / `reversed_by_id`).
* **Same transaction:** domain events fire *inside* the business transaction, so a sale and its journal entry commit or roll back together. A posting failure therefore blocks the sale; a test covers this.
* **No duplicated money:** Phase 2 `payments` rows are posted directly; the only change to `payments` is three nullable routing columns (`cash_register_id`, `cash_shift_id`, `bank_account_id`).

### Automatic postings
| Event | Journal |
|---|---|
| Sale completed | Dr AR / Cr Sales (net) / Cr VAT output; Dr COGS / Cr Inventory (cost snapshot) |
| Sale cancelled | Reversal of the sale entry (refund posts as a payment) |
| Return completed | Dr Sales returns + Dr VAT output / Cr AR; restocked lines: Dr Inventory / Cr COGS |
| Payment in / refund / supplier payment | Cash register, bank account or card clearing ↔ AR / AP (store credit goes through "customer credit") |
| Purchase received | Dr Inventory + Dr VAT input / Cr AP |
| Opening stock / manual receipt / adjustment ± | Inventory ↔ opening equity / goods-received-not-invoiced / shrinkage / count gains |
| Transfer received | Inventory moves between branch dimensions through inter-branch clearing |
| Expense approved | Dr expense category account + Dr VAT input / Cr cash, bank or accrued expenses |
| Payroll approved / paid | Dr salaries (base − deductions) + bonuses / Cr salaries payable + advances; then Dr salaries payable / Cr cash or bank |
| Employee advance | Dr employee advances / Cr cash or bank |
| Cash deposit / withdrawal / transfer | To-account / from-account (or the chosen counter account) |
| Shift opening / closing count | Difference to "cash over / short" |

Run `php artisan accounting:post-history` once after upgrading an installation that already has Phase 2 data. The command is idempotent.

### Reports (all from posted lines, filterable by branch and date)
Financial dashboard, income statement (period / monthly / annual comparison), balance sheet, trial balance, general ledger / account statement, cash flow (direct method), VAT report, receivables and payables aging (overdue after `payment_terms_days`), revenue (daily / monthly / annual), and branch performance. All of them print or save to PDF from the browser.

## 9. Phase 4 — Reporting and analytics

* `ReportEngine` runs every report from the posted data (sales, returns, stock movements, purchases) with branch scoping and an optional comparison period. `ReportExporter` writes CSV (UTF-8 BOM), XLSX (OpenSpout, streaming) and PDF (mPDF). Cells that start with `= + - @` are prefixed to block formula injection.
* `InsightsEngine` holds deterministic rules: declining products and branches, unusual stock movements, slow movers, reorder suggestions (lead time and safety days from Settings), linear-trend forecasts.
* `AiNarrator` (optional, `ERP_AI_ENABLED`) sends **only aggregated, branch-authorized figures** to the Anthropic API and returns a narrative. AI output never changes data; a human can turn reorder suggestions into **draft** purchase orders.

## 10. Phase 5 — Imports

Uploads and Google Sheets links are staged into `import_rows`, mapped (Arabic/English header synonyms), validated and then processed in chunks of 200, by the browser (polling) or by the every-minute cron. Existing stock is never overwritten: opening stock is applied only to products without movement history. Remote URLs go through `SafeUrlFetcher` (allow-listed https hosts, public IPs only, DNS pinning, size cap).

## 11. Phase 6 — Offline-first POS

* PWA: `public/sw.js` caches the app shell; `resources/js/offline/store.js` keeps the catalog snapshot and the sale queue in IndexedDB, encrypted with a non-extractable AES-GCM key.
* Devices: `pos_devices` binds a browser to one branch. The secret lives only in an HttpOnly, SameSite=Strict cookie; the server stores its SHA-256.
* Sync: `OfflineSyncService::sync` replays queued sales through `SaleService::complete` with the client UUID (idempotent). It keeps the offline time and the offline price. Stock shortages and price changes create `sync_conflicts` (policy *review* or *reject*); every attempt is written to `sync_logs`.
* `resources/js/offline/money.js` mirrors `TaxCalculator` exactly; `tests/js/money.test.mjs` proves both give the same integers.

## 12. Phase 7 — Operations

* `erp:backup` writes a driver-independent, gzipped NDJSON backup with a SHA-256 sidecar (optionally `--files`). `erp:restore` verifies the checksum and the schema version, empties the tables first (so cascades cannot drop restored rows) and checks row counts per table, all in one transaction.
* `erp:integrity` (read-only): stock = Σ ledger, ledger chain, balanced journals, trial balance, sale totals = Σ lines, every sale posted, paid amount = allocations, loyalty balance = Σ ledger.
* Both are scheduled nightly (`routes/console.php`). See `docs/SECURITY-AUDIT.md`, `docs/UAT-SCENARIOS.md`, `docs/VPS-MIGRATION.md`.

## 13. HR module

Extends the existing `employees` table (no parallel table): code, department, employment type and status, shift start, annual leave days, emergency contact, plus `national_id`, `address` and `emergency_contact_phone`, which are **encrypted at rest** and need `hr.sensitive` to view.

| Table | Purpose |
|---|---|
| `attendance_records` | one row per employee per day; lateness from `shift_start`; manual (`hr.manage`) or self check-in/out (`hr.self`) |
| `leave_requests` | annual/sick/unpaid/other; overlap check; annual balance per calendar year; nobody decides their own request |
| `salary_changes` | immutable history; written by `HrService::changeSalary` and by every salary change in Payroll |
| `performance_reviews` | 1–5 rating per period; no self-review |

Payroll integration: when a payroll run is created, `HrService::monthSummary` gives absences, half days and approved unpaid leave. The deduction `base × (absent + unpaid + ½·half days) ÷ days in month` is pre-filled, stored in `payroll_items.attendance_deduction`, and stays editable while the run is a draft.

## 14. Eyewear services module (`app/Modules/Optical`)

* **Prescriptions:** OD/OS SPH, CYL, AXIS, ADD, PD (binocular or monocular), contact-lens BC/DIA. Stored as integers (dioptres ×100, mm ×10); 0.25 steps and clinical ranges are validated (`Diopter`, `PrescriptionService::parseValues`). Records are never edited: they are voided with a reason and a new one is created. Medical notes are encrypted; scans use the private `attachments` table. Access: `prescriptions.view` / `prescriptions.manage` (new **optometrist** role).
* **Lenses:** lenses are catalog products (`ophthalmic_lens`, `contact_lens`) with range specs (`sph_min`, `sph_max`, `cyl_min`, `cyl_max`, `add_max`). A sale linked to a prescription is refused if a lens cannot cover it.
* **Warranties:** `products.warranty_months` creates a `warranties` row per sold line (listener on `SaleCompleted`). Cancellation voids it; returns reduce or void it. Claims are checked against the period and decided by someone other than their opener. Replacements and refunds go through Returns.
* **Repairs:** `repair_orders` with a status workflow. A charged delivery creates a normal invoice through `SaleService` using the configured service product, so tax, cash shifts and accounting apply. Warranty repairs are free.
* **Loyalty:** `loyalty_transactions` is an immutable ledger with a unique (source, type) key. Points are earned per currency unit × tier (bronze ×1, silver ×1.25, gold ×1.5) and redeemed as a server-validated reward discount (balance, minimum, maximum % of the invoice). Cancellation reverses earned points and restores redeemed ones; returns reverse points pro rata. Points are not booked as a liability (see the production readiness report).
