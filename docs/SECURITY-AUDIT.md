# Security audit (Phase 7)

Scope: the whole application at the end of Phase 7 (Phases 1–6, HR and eyewear modules). Method: code review of the access paths, targeted greps, dependency audits and the automated test suite. This is an internal review, not a third-party penetration test. Commission one before handling payment cards directly or exposing the system to the public.

## 1. Results summary

| Area | Status | Evidence |
|---|---|---|
| Authentication | ✅ | Session auth; `/login` throttled (`RateLimiter::for('login')`); passwords hashed (bcrypt); inactive users cannot sign in |
| Authorization (server-side) | ✅ | Every write service calls `Gate::authorize()` and `BranchAccess`; Livewire components re-authorize in `mount()` and actions; tests cover forbidden paths per role |
| Branch isolation | ✅ | `BranchAccess::authorize/allowedIds` on every query and write; "all branches" needs `branches.access-all`; offline devices are bound to one branch |
| Mass assignment | ✅ | No `$guarded = []`; explicit `$fillable`; status, cost and number fields set explicitly in services |
| SQL injection | ✅ | Query builder bindings everywhere; the few raw expressions (`periodExpr`, revenue grouping) come from fixed `match` whitelists |
| XSS | ✅ | Blade escaping; the only `{!! !!}` outputs are server-generated barcode/QR SVGs from validated codes |
| CSRF | ✅ | Laravel `PreventRequestForgery` + Livewire; offline sync uses the session plus a CSRF token |
| File uploads | ✅ | MIME + size validation; random file names; private disk; served only via `attachments.show` after permission and branch checks, with `nosniff`, a sandbox CSP and `no-store` |
| SSRF (imports) | ✅ | `SafeUrlFetcher`: https only, host allow-list, public-IP check, DNS pinning, manual redirects (max 3), size cap |
| Spreadsheet formula injection | ✅ | Exports prefix `= + - @` cells; imported formulas are flagged, not executed |
| Secrets | ✅ | No secrets in code; `.env` outside the web root; AI key only in `.env`; device secrets stored as SHA-256 hashes; device cookie HttpOnly, SameSite=Strict |
| Sensitive personal/medical data | ✅ | National ID, address, emergency phone and prescription notes encrypted at rest (`encrypted` cast); hidden from serialization; redacted in audit logs; visible only with `hr.sensitive` / `prescriptions.view` |
| Ledger immutability | ✅ | Inventory transactions, journal entries, payments, audit logs, salary history and the loyalty ledger cannot be updated or deleted through the models; corrections are reversals |
| Idempotency / replay | ✅ | UUIDs on sales, payments, refunds, offline sync; unique keys on ledger sources |
| Security headers | ✅ | `X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy`, HSTS over HTTPS |
| Session | ✅ | `.env.example`: `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`, HttpOnly; production needs `APP_DEBUG=false` |
| Dependencies | ✅ | `composer audit`: no advisories · `npm audit --omit=dev`: 0 vulnerabilities (2026-11-17) |
| AI data exposure | ✅ | Disabled by default; only aggregated figures for authorized branches are sent; AI output never changes data (it can only create **draft** purchase orders after a human clicks) |
| Backups | ✅ | Outside the web root; SHA-256 verified before restore; encrypted fields stay encrypted inside backups |

## 2. Findings fixed during this audit

1. **Audit log could store personal data in clear text.** Employee updates logged `national_id`, `address` and `emergency_contact_phone` values. Fixed: `AuditLogger` now replaces these (and prescription values) with `[redacted]`, while still recording which field changed. Covered by `HrTest::test_sensitive_fields_need_permission_are_encrypted_and_redacted_in_audit`.
2. **Repair delivery form loaded a repair from another branch.** `RepairIndex::startDelivery` read the estimated cost of any repair ID. The delivery itself was already blocked by the service, but the cost leaked. Fixed: branch authorization added.
3. **Restore could silently lose child rows** because of `ON DELETE CASCADE` during table replacement. Fixed: all tables are emptied before any row is inserted, and row counts are verified per table. Covered by `BackupRestoreTest`.

## 3. Residual risks and recommendations

| Risk | Recommendation |
|---|---|
| No two-factor authentication | Enable 2FA for administrators and accountants before go-live (planned enhancement; the `two_factor_secret` column is already redacted in audit logs) |
| Offline POS prices are trusted by design | Price differences are flagged as conflicts for review; review the *Offline sync review* page daily |
| A stolen, still-registered POS device could queue sales | Revoke lost devices immediately (*Offline sync review → Devices*); a device only works with a valid user session for its branch |
| Shared hosting (shared IP and CPU) | Use the VPS guide when the business grows; keep LiteSpeed cache off for the app |
| Backups stored on the same server | Download them off-site weekly (DEPLOYMENT-HOSTINGER §11) |
| `APP_KEY` loss makes encrypted fields unreadable | Store `APP_KEY` in a password manager; never rotate it without a re-encryption plan |
| Loyalty points are not booked as a liability | Acceptable for small programs; ask the accountant whether IFRS 15 deferral is needed (see the production readiness report) |
| No external penetration test | Commission one before opening the system to the internet beyond staff use |

## 4. Production checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`
- [ ] HTTPS forced; `SESSION_SECURE_COOKIE=true`; `SESSION_ENCRYPT=true`
- [ ] `.env` permissions 600 and outside `public_html`
- [ ] Only `public/` is web-accessible (try to open `/.env`, `/storage/logs/laravel.log` and `/composer.json`: each must return 404)
- [ ] Super-admin created with a strong password; demo accounts not present
- [ ] Every user has the least-privilege role and only their branches
- [ ] Cron entry present; `schedule:list` shows backup and integrity jobs
- [ ] First backup downloaded off-site; `--verify-only` passes on it
- [ ] `ERP_AI_ENABLED=false` unless you approve sending aggregated figures to the AI provider
