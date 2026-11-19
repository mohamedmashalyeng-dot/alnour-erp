# Moving from Hostinger shared hosting to a VPS

The application has no hosting-specific code. Moving to a VPS needs no code changes. It does let you switch on things that shared hosting cannot run (queue workers, Redis, real cron, OPcache tuning).

## When to move

Consider a VPS when one of these is true:

* more than ~8 branches, or more than ~15 users working at the same time;
* reports or PDF exports regularly take more than 10 seconds;
* imports of more than ~20,000 rows are frequent;
* you need guaranteed CPU/RAM, server-level backups or a staging copy.

## Target setup (example: Ubuntu 24.04, 2 vCPU / 4 GB)

| Component | Recommended |
|---|---|
| Web | Nginx + PHP-FPM 8.3/8.4 (extensions as in DEPLOYMENT-HOSTINGER §1, plus `opcache`, `redis`) |
| Database | MySQL 8.0+ or MariaDB 10.11+ (InnoDB, `utf8mb4`); `innodb_buffer_pool_size` ≈ 50% of RAM |
| Cache / sessions | Redis (optional; database driver keeps working) |
| Queue | `QUEUE_CONNECTION=database` or `redis`, plus a Supervisor-managed `queue:work` (optional; everything also works synchronously) |
| TLS | Let's Encrypt (certbot) |
| Firewall | ufw: allow 22 (key-only SSH), 80, 443 |

## Migration steps

1. **Freeze:** on Hostinger, `php artisan down`.
2. **Backup:** `php artisan erp:backup --files`. Download the `.ndjson.gz`, its `.sha256` and `…-files.zip`. Also export the database from phpMyAdmin as a second safety copy.
3. **Copy secrets:** copy the production `.env` values by hand. **`APP_KEY` must be the same** (encrypted employee and prescription fields depend on it).
4. **Provision the VPS:** install the stack above, create the database and user (`GRANT ALL ON erp.* TO 'erp'@'localhost'`).
5. **Deploy the code:** `git clone` or upload; `composer install --no-dev -o`; upload `public/build` or run `npm ci && npm run build`.
6. **Configure:** `.env` with the new DB credentials, `APP_URL`, and optionally `CACHE_STORE=redis`, `SESSION_DRIVER=redis`. Keep `SESSION_SECURE_COOKIE=true`.
7. **Schema:** `php artisan migrate --force && php artisan db:seed --force` (creates empty tables and roles).
8. **Data:** copy the backup to the server, then:
   ```bash
   php artisan erp:restore /path/erp-YYYYMMDD-HHMMSS.ndjson.gz --verify-only
   php artisan erp:restore /path/erp-YYYYMMDD-HHMMSS.ndjson.gz --force
   unzip erp-…-files.zip -d storage/app/      # restores private/ and public/
   php artisan storage:link
   php artisan erp:integrity                  # must pass
   ```
   The backup format is driver-independent, so a backup made on Hostinger restores on the VPS (and SQLite ↔ MySQL works too).
9. **Web server:** document root = `/var/www/erp/public`; `try_files $uri $uri/ /index.php?$query_string;`; deny `/\.(?!well-known)`; `client_max_body_size 12m;`; serve `sw.js` with `Cache-Control: no-cache`.
10. **Scheduler:** `* * * * * www-data cd /var/www/erp && php artisan schedule:run >> /dev/null 2>&1`
11. **Optional queue worker** (Supervisor):
    ```ini
    [program:erp-worker]
    command=php /var/www/erp/artisan queue:work --sleep=3 --tries=3 --max-time=3600
    user=www-data
    autostart=true
    autorestart=true
    ```
12. **Cut-over:** lower the DNS TTL a day before, point DNS to the VPS, check HTTPS, sign in, run one test sale and one return in a test branch, then cancel them through the normal process.
13. **Offline POS devices:** devices are bound by a cookie for the domain. If the domain stays the same, nothing changes. If it changes, register each device again. Sync any queued offline sales **before** the cut-over (the Offline POS page shows the queue).
14. Keep the Hostinger site in maintenance mode for a few days as a fallback, then cancel it.

## After the move

* `php artisan optimize` after every deploy; enable OPcache (`opcache.validate_timestamps=0` in production, reload PHP-FPM on deploy).
* Off-site backups: copy `storage/app/backups` nightly to object storage (S3-compatible, using `rclone`). Keep 30 days.
* Monitoring: watch `storage/logs/laravel.log` for `erp:integrity found problems` and `erp:backup failed`, or ship the logs to a log service.
* Run `composer audit` and `npm audit` monthly.
