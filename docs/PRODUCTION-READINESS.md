# Production readiness report

Date: 2026-11-17 · Scope: Phases 1–7, HR module, eyewear services module · **Nothing has been deployed.**

## 1. Verdict

**Ready for staging and user acceptance testing, not yet for live trading.** All planned features are implemented and covered by automated tests, which pass on SQLite. Before going live, four things are still required (§5): a run of the test suite on MySQL, the UAT sign-off, a restore drill on the real hosting, and the security checklist.

## 2. What was verified, and how

| Check | Result |
|---|---|
| Automated test suite (PHPUnit, SQLite in-memory) | **175 tests, 875 assertions, all passing** |
| Offline POS maths vs server maths (`node --test`) | 5/5 passing: identical integers for VAT inclusive/exclusive, discount allocation, parsing |
| Arabic translations | `TranslationsTest` passes: every UI string has an Arabic entry |
| Fresh migration of the local database (all 3 new migrations) | Done; `erp:integrity` → all 8 checks pass |
| Real backup → `--verify-only` on the local database | 63 tables, checksum OK; files archive created |
| Backup → corrupt → restore → integrity (automated) | Data identical after restore, including Arabic text and encrypted fields; tampered or unverified files refused |
| Volume test (5,000 products × 3 branches, 10,000 invoices / 20,000 lines) | POS search ≈ 4 ms, barcode ≈ 0.4 ms, stock page ≈ 2 ms, 90-day sales reports ≈ 70 ms, all integrity checks ≈ 50 ms (SQLite, laptop) |
| `composer audit` / `npm audit --omit=dev` | No known vulnerabilities |
| Code style (Pint) | Clean |

## 3. What each phase delivers

| Phase / module | Main capabilities |
|---|---|
| 1 Foundation & inventory | Branches, users and roles, products with barcode/QR, multi-branch stock, transfers with approval, immutable stock ledger |
| 2 Commerce | Suppliers, purchase orders and receiving, customers, POS, sales, returns/exchanges with inspection, payments and refunds |
| 3 Accounting | Double-entry ledger posted automatically, chart of accounts, cash shifts, bank reconciliation, expenses, payroll, VAT, statements, period closing |
| 4 Analytics | Report center with comparison periods, executive dashboard, insights (declines, slow movers, reorder, forecast), optional AI narrative, PDF/Excel/CSV |
| 5 Imports | Excel/CSV/Google Sheets products with mapping, validation, duplicate handling, history, error report; SSRF-safe |
| 6 Offline POS | PWA, encrypted local queue, device binding, idempotent sync, oversell/price conflicts with review |
| 7 Operations | Verified backup/restore, integrity checks, schedule, security audit, UAT script, Hostinger and VPS guides |
| HR | Employee files with encrypted IDs, attendance (manual and self), leave with balances, salary history, reviews, performance report, payroll deductions |
| Eyewear | Prescriptions (validated, never edited, restricted), lens compatibility, warranties and claims, repairs invoiced through sales, loyalty points with tiers |

## 4. Known limitations (honest list)

1. **MySQL not run yet.** All tests ran on SQLite. The code uses portable queries and `phpunit.mysql.xml` is ready, but no MySQL server was available on this machine. Run `phpunit -c phpunit.mysql.xml` against an empty MySQL database before go-live.
2. **Concurrency** is protected by row locks (`SELECT … FOR UPDATE`) and unique keys, but it has not been tested under truly parallel load (SQLite serialises writes). On MySQL, check that every table uses InnoDB.
3. **ZATCA (Saudi e-invoicing) is not implemented.** Invoices carry a simplified QR, which is not a ZATCA Phase 2 integration. If you are subject to ZATCA Phase 2, treat it as a separate project before going live.
4. **No two-factor authentication** yet (see SECURITY-AUDIT §3).
5. **Loyalty points are not booked as a liability.** Redemptions reduce revenue as a discount when used. For a large program, ask your accountant about IFRS 15 deferral.
6. **Offline sales keep the device price** (by design, so the customer pays what they were told). Differences are flagged for review, not corrected automatically.
7. **Warranty replacements and refunds** go through Returns & exchanges; the claim records the decision, not the stock or money movement.
8. **Leave counts calendar days** (weekends and holidays included). Adjust the days manually if your policy counts working days only.
9. **AI insights** depend on the external Anthropic API and are disabled by default. Enabling them sends aggregated figures (no customer personal data) outside your server.
10. **Shared hosting** has no workers: PDFs, exports and imports run within requests or the cron. Very large exports may need the VPS.

## 5. Go-live checklist

1. [ ] Run the test suite on MySQL (`phpunit -c phpunit.mysql.xml`): all green.
2. [ ] Staging site on the target hosting; follow DEPLOYMENT-HOSTINGER §1–6 and §10.
3. [ ] Load real master data (branches, users, products, opening stock) via the import tool; check stock and prices with the branch managers.
4. [ ] Run every scenario in UAT-SCENARIOS.md; get sign-off from the owner and the accountant.
5. [ ] Restore drill: `erp:backup` → restore into a second database → `erp:integrity` passes.
6. [ ] SECURITY-AUDIT §4 checklist done; `APP_KEY` stored in a password manager.
7. [ ] Accountant reviews the chart of accounts and posting rules, VAT settings and the period-closing policy.
8. [ ] Decide on ZATCA and 2FA (blocking or not for your business).
9. [ ] Go live; for the first week, check *Offline sync review* and the output of `erp:integrity` every day.

## 6. Suggested next work (by value)

1. MySQL verification run and fixes, if any.
2. ZATCA Phase 2 e-invoicing (if applicable).
3. Two-factor authentication for admins and accountants.
4. Customer-facing notifications (SMS/WhatsApp when a repair is ready or a prescription expires).
5. Loyalty liability accounting, if the program grows.
