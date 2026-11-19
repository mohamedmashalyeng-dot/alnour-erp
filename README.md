# Eyewear ERP

Multi-branch ERP for an eyewear retail business. The interface is Arabic-first (RTL), with English available.

**Status:** all planned phases are implemented and tested locally (SQLite). The system has **not been deployed** and has not yet been run against MySQL; see the [production readiness report](docs/PRODUCTION-READINESS.md).

* Phase 1: foundation, products, multi-branch inventory
* Phase 2: purchasing, suppliers, customers, POS, sales, returns, payments
* Phase 3: double-entry accounting, cash and bank, expenses, payroll, financial statements, tax, period closing
* Phase 4: report center, executive dashboard, business insights, optional AI narrative, exports to PDF, Excel and CSV
* Phase 5: Excel/CSV/Google Sheets product import with mapping, validation, duplicate handling, history
* Phase 6: offline-first PWA POS with an encrypted local queue, idempotent sync and conflict review
* Phase 7: verified backup/restore, integrity checks, security audit, performance check, UAT, deployment guides
* HR: employee files, attendance, leave, salary history, performance (extends the payroll employee record)
* Eyewear services: prescriptions, lens compatibility, warranties and claims, repairs, loyalty points

## Documentation

| Document | For |
|---|---|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Developers: modules, database, rules, every phase |
| [docs/DEPLOYMENT-HOSTINGER.md](docs/DEPLOYMENT-HOSTINGER.md) | Going live on Hostinger shared hosting, upgrades, backups |
| [docs/VPS-MIGRATION.md](docs/VPS-MIGRATION.md) | Moving to a VPS later |
| [docs/SECURITY-AUDIT.md](docs/SECURITY-AUDIT.md) | Security review, residual risks, go-live security checklist |
| [docs/UAT-SCENARIOS.md](docs/UAT-SCENARIOS.md) | Acceptance tests to run with staff before go-live |
| [docs/PRODUCTION-READINESS.md](docs/PRODUCTION-READINESS.md) | What is done, what is verified, known limitations |
| [CLAUDE.md](CLAUDE.md) / [AGENTS.md](AGENTS.md) | Rules for coding agents |

## Local setup

Requirements: PHP 8.3+ with extensions bcmath, ctype, curl, dom, fileinfo, gd, intl, mbstring, openssl, pdo_mysql (and pdo_sqlite for tests), zip; Composer 2; Node 20+; MySQL 8 / MariaDB 10.6+.

```bash
composer install
cp .env.example .env && php artisan key:generate    # then set DB_* in .env
php artisan migrate --seed                          # schema + roles/permissions + chart of accounts
php artisan erp:create-admin                        # first super administrator
php artisan storage:link
npm install && npm run build
php artisan serve
```

Optional local demo data (never in production): `php artisan db:seed --class=DemoSeeder`. It creates 3 branches, 12 products, and the users `admin@example.test` / `Demo-Admin-2026` and `cashier@example.test` / `Demo-Cashier-2026`.

## Tests

```bash
php artisan test                                    # SQLite in-memory (default)
php vendor/bin/phpunit -c phpunit.mysql.xml         # same suite on MySQL: create an empty `erp_testing` database
                                                    # and set DB_HOST/DB_USERNAME/DB_PASSWORD in the environment first
node --test "tests/js/*.test.mjs"                   # offline POS maths = server maths
php artisan test --group=performance                # volume smoke test only
```

## Operations

```bash
php artisan erp:backup --files          # verified backup to storage/app/backups (+ private uploads)
php artisan erp:restore <file> --verify-only
php artisan erp:restore <file> --force  # replaces all data (take a fresh backup first)
php artisan erp:integrity               # read-only consistency checks
```

The scheduler (one cron entry: `php artisan schedule:run` every minute) runs imports, the nightly backup and the integrity checks.

## Roles

| Role | Typical user |
|---|---|
| super-admin | Owner: everything, all branches |
| admin | Head office: everything except creating other admins |
| branch-manager | Runs assigned branches: stock, transfers, purchasing, sales, returns approval, HR (no salaries or IDs), warranty decisions |
| inventory-clerk | Receiving, transfers, labels |
| cashier | POS, customers, returns intake, payments, repairs, warranty claims, own attendance and leave |
| accountant | Financial views across all branches, payments, payroll, sensitive HR data |
| optometrist | Prescriptions (create/void), customers |

Permissions per role are defined in `app/Modules/Core/Permissions.php`. Run `php artisan db:seed` again after changing them.
