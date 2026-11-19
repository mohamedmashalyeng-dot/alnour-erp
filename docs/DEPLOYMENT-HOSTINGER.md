# Deploying to Hostinger Shared Business

> Nothing has been deployed. Follow these steps only when you decide to go live.

## 1. Requirements check (hPanel)

| Item | Requirement | Where |
|---|---|---|
| PHP | **8.3 or 8.4** (Laravel 13 needs ≥ 8.3) | hPanel → Advanced → PHP Configuration |
| PHP extensions | bcmath, ctype, curl, dom, fileinfo, gd, intl, mbstring, openssl, pdo_mysql, tokenizer, xml, zip | same page → Extensions |
| PHP limits | `memory_limit` ≥ 256M, `upload_max_filesize` ≥ 8M, `max_execution_time` ≥ 60 | same page → Options |
| Database | MySQL/MariaDB (one database for all branches) | hPanel → Databases → MySQL |
| SSL | Free SSL enabled, force HTTPS | hPanel → Security → SSL |
| SSH | Available on Business plans (hPanel → Advanced → SSH Access). If you do not use it, see the "No SSH" notes below | |
| Cron | Available | hPanel → Advanced → Cron Jobs |
| Not available | Persistent queue workers, WebSockets, Redis | the app is built to need none of these |

## 2. Build locally (one time per release)

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build          # produces public/build (must be uploaded: shared hosting has no Node)
```

## 3. Directory layout (do NOT expose the source code)

Upload the project **outside** `public_html`, and serve only the `public` folder:

```
/home/u123456789/
├── erp/                 ← whole Laravel project (app, vendor, .env, storage …)
└── domains/erp.example.com/public_html/  ← contents of erp/public ONLY
```

Edit `public_html/index.php` so it points at the project:

```php
require __DIR__.'/../../../erp/vendor/autoload.php';
$app = require_once __DIR__.'/../../../erp/bootstrap/app.php';
```

(Adjust the relative depth to your actual folders.) The alternative, if you can set it in hPanel, is to point the domain's document root directly at `erp/public`.

`.env`, `vendor/`, `storage/` and the source code then live outside the web root, so they cannot be downloaded.

## 4. Environment

Copy `.env.example` → `erp/.env` and set:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://erp.example.com
APP_KEY=            # php artisan key:generate --show (run locally, paste the value)
APP_TIMEZONE=Asia/Riyadh   # set once before go-live; do not change after data exists
DB_DATABASE / DB_USERNAME / DB_PASSWORD   # from hPanel
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
QUEUE_CONNECTION=sync
ERP_CURRENCY=SAR  ERP_CURRENCY_DECIMALS=2  ERP_CURRENCY_SYMBOL="ر.س"
```

Set file permissions to `600` for `.env` and `775` for `storage/` and `bootstrap/cache/`.

## 5. First-time setup (via SSH)

```bash
cd ~/erp
php artisan migrate --force
php artisan db:seed --force                 # roles & permissions only (no fake data)
php artisan erp:create-admin                # creates the super administrator interactively
php artisan storage:link                    # product images (see note below)
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

**Storage link with a split layout:** `storage:link` creates `erp/public/storage`. With the split layout from step 3, create the link from `public_html/storage` to `~/erp/storage/app/public` instead (`ln -s ~/erp/storage/app/public ~/domains/.../public_html/storage`).

### No SSH?

Use a temporary cron job (hPanel → Cron Jobs), let it run once, then **delete it**:

```
cd /home/u123456789/erp && /usr/bin/php artisan migrate --force && /usr/bin/php artisan db:seed --force
cd /home/u123456789/erp && /usr/bin/php artisan erp:create-admin --name="Owner" --email=owner@example.com --password='Choose-A-Strong-Pass1'
```

Change that password right after the first login: a password passed on a command line can be stored in the cron history.

## 6. Cron (scheduler)

Add one cron entry (every minute, or every 5 minutes):

```
* * * * * cd /home/u123456789/erp && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

**This cron entry is required.** It runs:

| Job | When | What |
|---|---|---|
| `imports:run --seconds=50` | every minute | processes spreadsheet imports in chunks (no queue workers on shared hosting) |
| `erp:backup --keep=14` | daily 01:30 | verified database backup to `storage/app/backups` (keeps 14) |
| `erp:integrity` | daily 02:15 | read-only consistency checks; problems are written to `storage/logs` |

Check `php artisan schedule:list` once after setup.

## 7. Updates

1. Put the site in maintenance mode: `php artisan down`.
2. Upload new code + `public/build`.
3. Run `composer install --no-dev -o` (or upload `vendor/`), then `php artisan migrate --force`, `php artisan db:seed --force` (to sync any new permissions), `php artisan optimize`.
4. Bring the site back: `php artisan up`.

Back up **before** every migration: `php artisan erp:backup --files` (or hPanel → Databases → Backups). Then run `php artisan erp:integrity` after the update; it must report "All integrity checks passed".

## 8. Known hosting limitations

* No background workers: PDFs and reports are generated within the request. Document endpoints are rate-limited to protect the shared CPU.
* Concurrency: row locking (`SELECT … FOR UPDATE`) needs InnoDB, which is the default on Hostinger. Check that every table uses InnoDB in phpMyAdmin.
* Large imports (Phase 5) are processed in chunks of 200 rows, driven by the open import page (polling) or by the every-minute cron.
* Offline POS sync (Phase 6) uses plain HTTPS requests (no WebSockets). Service workers **require HTTPS**.
* AI insights (Phase 4) call the Anthropic API within the request (90 s timeout, cached 1 hour). Keep `ERP_AI_ENABLED=false` unless you need them.
* If the business outgrows shared hosting (more branches, heavy reporting), move to a VPS. Nothing in the code depends on Hostinger.

## 9. Upgrading to Phase 3 (accounting)

1. Back up the database.
2. `php artisan migrate --force` (additive tables, plus 3 nullable columns on `payments`).
3. `php artisan db:seed --force` (new permissions and the default chart of accounts; existing accounts are never overwritten).
4. `php artisan accounting:post-history` (posts all existing sales, purchases, returns, payments and stock movements; safe to re-run).
5. In **Settings**, review *Cashiers must open a cash shift* (on by default) and *Payment terms*.
6. Ask your accountant to review **Chart of accounts → Posting rules** before go-live.

## 10. Upgrading to Phases 4–7, HR and the eyewear module

All new migrations are additive (new tables plus new nullable/defaulted columns on `employees`, `sales`, `products`, `customers`, `payroll_items`).

1. `php artisan down`
2. `php artisan erp:backup --files` and **download** the two newest files from `storage/app/backups` to your computer.
3. Upload the new code and `public/build`, including `public/sw.js`, `public/manifest.webmanifest` and `public/offline.html` (they must sit at the web root, next to `index.php`).
4. `php artisan migrate --force`
5. `php artisan db:seed --force` (new permissions and the new **optometrist** role; existing roles are re-synced to their defaults, so re-check any role you customised).
6. `php artisan optimize`
7. `php artisan erp:integrity` must pass, then `php artisan up`.
8. In **Settings**, review the new options: reorder lead/safety days, offline POS overselling policy and maximum age, loyalty program (off by default) and the service product used to invoice repairs.
9. For each product with a warranty, set *Warranty (months)* on the product form. Warranties are created only for sales made after this.

### LiteSpeed cache / service worker

Hostinger's LiteSpeed cache must not cache the app. If the LiteSpeed Cache is enabled for the domain, exclude everything (the ERP is fully dynamic), or at least `/sw.js`, `/manifest.webmanifest` and `/pos/offline*`. `sw.js` is versioned in its code, and browsers re-check it on every page load.

## 11. Backups and restore

* **Automatic:** `erp:backup` runs nightly and keeps 14 copies in `storage/app/backups` (outside the web root). Each `.ndjson.gz` file has a `.sha256` checksum next to it.
* **Off-site:** shared-hosting disks can fail together with the database. At least weekly, download the newest backup **and** its `.sha256` (File Manager or SFTP), and also keep hPanel's own backups enabled.
* **Uploaded files:** `php artisan erp:backup --files` also writes `…-files.zip` with private uploads (expense receipts, prescription scans) and product images.
* **APP_KEY:** employee national IDs, addresses and prescription medical notes are encrypted with `APP_KEY`. A backup can only be decrypted with the same key. Store the key in a password manager, separately from the backups.
* **Check a backup without touching data:** `php artisan erp:restore storage/app/backups/<file> --verify-only`
* **Restore** (replaces all data): `php artisan down`, then take a fresh backup of the current state, `php artisan erp:restore <file> --force`, `php artisan erp:integrity`, `php artisan up`. Files: unzip `…-files.zip` into `storage/app/` (`private/` and `public/`).
* **Restore drill:** once a month, restore the newest backup into a separate test database (a second MySQL database in hPanel plus a copy of the app with its own `.env`) and run `erp:integrity`. A backup that has never been restored is not a backup.
