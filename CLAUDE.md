# Eyewear ERP — notes for coding agents

Read `docs/ARCHITECTURE.md` before changing anything.

## Non-negotiable rules
- Money = integer minor units (BIGINT). Use `App\Modules\Core\Support\Money`. Never floats.
- Stock changes go ONLY through `App\Modules\Inventory\Services\InventoryService::move()` (it locks the row and writes the ledger).
- Payments go ONLY through `App\Modules\Payments\Services\PaymentService` (idempotent via UUID, validates against the live balance).
- Every write service calls `Gate::authorize(...)` and `BranchAccess` itself; never rely on the UI for permissions.
- Ledgers are immutable: `inventory_transactions`, `audit_logs`, `payments`, journal entries, `salary_changes`, `loyalty_transactions` (no update/delete; correct with reversals).
- Sales side effects (accounting, warranties, loyalty) hang off the sale events dispatched INSIDE the sale transaction; add listeners, do not call them from the UI.
- Personal/medical fields use the `encrypted` cast, are `$hidden`, and are listed in `AuditLogger::REDACTED`. Prescriptions are never edited (void + new).
- Dynamic translation keys must end with "." before the variable part, e.g. `__('leave.status.'.$s)`.
- Before migrations in production: `php artisan erp:backup --files`; after: `php artisan erp:integrity`.
- Every user-facing string goes through `__()` and needs an entry in `lang/ar.json` (`TranslationsTest` enforces this).
- New permissions go in `App\Modules\Core\Permissions`, then run `php artisan db:seed`.

## Environment
- Local PHP has no php.ini; tests need extensions enabled (mbstring, openssl, pdo_sqlite, fileinfo, gd, intl, zip).
- Run tests: `php artisan test` (SQLite in-memory). MySQL run: `phpunit -c phpunit.mysql.xml`.
- Target hosting: Hostinger Shared Business (no workers, Redis or WebSockets).
