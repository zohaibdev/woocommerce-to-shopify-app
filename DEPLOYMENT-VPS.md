# Deploying Store Shift to a VPS (Ubuntu 24.04 + Apache)

For a server you have root on. If you are on Hostinger **shared** hosting, use
[DEPLOYMENT.md](DEPLOYMENT.md) instead — the two differ mainly in how the queue
workers are run, which is the single biggest factor in how long a large
migration takes.

Target: `https://shopshift.net/` (landing) and `/app/` (the app).

---

## 0. Before you touch the server ⚠️

### 0.1 `APP_KEY` — carry it over, do NOT regenerate

Every merchant's Shopify access token and every WooCommerce key/secret in the
database is encrypted with `APP_KEY`. Generate a new one on the VPS and **every
existing install breaks**: tokens cannot be decrypted, merchants have to
reinstall the app, and stored Woo credentials are lost for good (the form can
never show a secret back, so they must issue new key pairs in WordPress).

Copy the exact `APP_KEY=base64:...` line from the old server's `.env` and keep it
somewhere safe before you begin.

```bash
# on the OLD server
grep '^APP_KEY=' ~/public_html/app/backend/.env
```

`php artisan key:generate` is only correct on a genuinely fresh install with no
shops in the database.

### 0.2 Dump the existing database

```bash
# on the OLD server
mysqldump -u ss_root -p --single-transaction --routines ss_main > ~/ss_main.sql
```

Download it. You will restore it in step 9.

### 0.3 DNS

Point `shopshift.net` (A record) at the VPS IP. Do this **first** —
certbot in step 8 verifies over HTTP and will fail until DNS has propagated.
Keep the TTL low (300s) until you have finished, so a rollback is quick.

---

## 1. First login and basic hardening

```bash
ssh root@YOUR_SERVER_IP
adduser deploy && usermod -aG sudo deploy
rsync --archive --chown=deploy:deploy ~/.ssh /home/deploy/
```

Then reconnect as `deploy` and lock the server down:

```bash
sudo ufw allow OpenSSH && sudo ufw allow 'Apache Full' && sudo ufw --force enable
sudo timedatectl set-timezone UTC
```

> UTC matters more than it looks. Shopify session tokens are rejected for clock
> skew (`not_yet_valid`), and `BillingPeriod` counts a merchant's allowance month
> from their own billing date. Also install NTP sync if it is not on:
> `sudo timedatectl set-ntp true`.

---

## 2. Install the stack

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y apache2 php8.3 php8.3-fpm php8.3-mysql php8.3-mbstring \
  php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl php8.3-redis \
  mysql-server redis-server supervisor git unzip \
  certbot python3-certbot-apache
```

```bash
sudo a2enmod rewrite proxy_fcgi setenvif
sudo a2enconf php8.3-fpm
sudo a2dissite 000-default
sudo systemctl restart apache2
```

Confirm the versions the app actually depends on:

```bash
php -v                        # 8.3.x
mysql -V                      # must be 8.0.1 or newer — see below
redis-cli ping                # PONG
```

> **MySQL 8.0.1+ is not optional.** Laravel's queue only uses
> `FOR UPDATE SKIP LOCKED` on MySQL 8.0.1+/MariaDB 10.6+. Below that it falls
> back to a plain `FOR UPDATE`, and your eight workers block each other one at a
> time — you pay for a worker pool and get a single worker's throughput. Ubuntu
> 24.04's `mysql-server` is 8.0.x, so this is only a risk if you installed
> MariaDB or reused an older server.

### 2.1 PHP limits

```bash
sudo sed -i 's/^memory_limit = .*/memory_limit = 512M/' /etc/php/8.3/fpm/php.ini
sudo sed -i 's/^max_execution_time = .*/max_execution_time = 120/' /etc/php/8.3/fpm/php.ini
sudo sed -i 's/^memory_limit = .*/memory_limit = 512M/' /etc/php/8.3/cli/php.ini
sudo systemctl restart php8.3-fpm
```

The **CLI** limit is the one the queue workers use. A batch holds a page of up to
100 WooCommerce products in memory, and a product with heavy `meta_data` is not
small — the default 128M is where a big catalogue dies.

### 2.2 Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

---

## 3. MySQL

```bash
sudo mysql_secure_installation
```

```bash
sudo mysql
```

```sql
CREATE DATABASE ss_main CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ss_root'@'localhost' IDENTIFIED BY 'A_STRONG_PASSWORD_HERE';
GRANT ALL PRIVILEGES ON ss_main.* TO 'ss_root'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Tuning — create `/etc/mysql/mysql.conf.d/store-shift.cnf`:

```ini
[mysqld]
innodb_buffer_pool_size = 2G          # ~50% of RAM on an 8GB box
innodb_flush_log_at_trx_commit = 2
max_allowed_packet = 64M
max_connections = 200
```

```bash
sudo systemctl restart mysql
```

> `max_allowed_packet` matters even on Redis: it is what let the old database
> cache store a WooCommerce page in one row. On Redis the page cache no longer
> touches MySQL at all, but 64M costs nothing and removes a whole class of
> "batch mysteriously slow" reports.

---

## 4. Redis

This is the biggest single database win for this app. It takes the queue polling,
the page cache, the `SourceHealth` telemetry and the `advance()` lock off MySQL
entirely.

Edit `/etc/redis/redis.conf`:

```
maxmemory 512mb
maxmemory-policy noeviction
```

```bash
sudo systemctl restart redis-server && sudo systemctl enable redis-server
```

> ⚠️ **`noeviction` is deliberate.** Redis holds both the cache *and the job
> queue*. Under an eviction policy like `allkeys-lru`, a full Redis would quietly
> delete **queued jobs** — which is a merchant's catalogue silently not
> migrating. With `noeviction` a full Redis refuses writes instead: cache writes
> degrade harmlessly (that is exactly what `App\Support\SafeCache` exists for)
> and anything more serious fails loudly. 512MB is generous — the page cache
> holds at most a few MB per in-flight batch for two hours.

---

## 5. Get the code

The built frontend assets are committed to the repo, so the server never needs
Node. A clone is the whole deploy.

```bash
sudo mkdir -p /var/www && sudo chown deploy:deploy /var/www
cd /var/www
git clone https://github.com/zohaibdev/store-shift.git store-shift
cd store-shift/app/backend
composer install --no-dev --optimize-autoloader
```

The repository root **is** the web root: `index.html` (landing page), `app/`,
`privacy/`, `terms/` and so on all sit at the top level, exactly as they do in
`public_html` on shared hosting.

---

## 6. `.env`

```bash
cd /var/www/store-shift/app/backend
cp .env.production .env
chmod 600 .env
nano .env
```

The values that must be right:

```ini
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...            # ← FROM THE OLD SERVER (step 0.1)
APP_URL=https://shopshift.net/app

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=ss_main
DB_USERNAME=ss_root
DB_PASSWORD=A_STRONG_PASSWORD_HERE

# --- Redis for cache + queue ---
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_QUEUE_RETRY_AFTER=900   # never below ~600 — see the warning below
SESSION_DRIVER=database       # survives a Redis restart; OAuth state lives here

# --- Workers: supervisor runs them, so the app must not ---
QUEUE_AUTO_WORKER=false
MIGRATION_MAX_CONCURRENCY=8

SHOPIFY_API_KEY=...
SHOPIFY_API_SECRET=...
SHOPIFY_APP_URL=https://shopshift.net/app
SHOPIFY_BILLING_TEST=false    # ← merchants are never charged while this is true

ADMIN_EMAIL=you@example.com
ADMIN_PASSWORD=               # set before seeding, then blank it out again
```

> ⚠️ **`retry_after` must exceed every job's own timeout.** `StartMigrationJob`
> allows itself 600s, `ProcessBatchJob` 300s. Set `retry_after` below those and
> the queue decides a *running* batch is dead and hands the same page to a second
> worker — every item gets written to Shopify twice and the run eventually
> freezes at *processing* with no error. This is locked by a test
> (`ThroughputHardeningTest::every_queue_connection_waits_longer_than_the_longest_job`),
> but only the config default is; if you override it in `.env`, keep it ≥ 600.

---

## 7. Permissions

Apache/PHP-FPM runs as `www-data`, and so will the workers. `deploy` owns the
code so `git pull` keeps working.

```bash
cd /var/www/store-shift
sudo chown -R deploy:www-data .
sudo find . -type d -exec chmod 755 {} \;
sudo find . -type f -exec chmod 644 {} \;

# only these two need to be writable by the web/worker user
sudo chown -R www-data:www-data app/backend/storage app/backend/bootstrap/cache
sudo chmod -R 775 app/backend/storage app/backend/bootstrap/cache
sudo chown deploy:www-data app/backend/.env && sudo chmod 640 app/backend/.env
```

> ⚠️ **640, not 600.** PHP-FPM runs as `www-data`, so `www-data` has to be able to
> READ `.env`. At 600 only the owner can, and the failure is silent and
> bewildering: Laravel boots perfectly, `config('app.url')` quietly falls back to
> `http://localhost`, and `SHOPIFY_API_KEY` and `SHOPIFY_API_SECRET` come back
> empty — so every App Bridge session token fails its signature check and the
> merchant sees "Your session expired. Reload the page to continue." on a server
> where nothing is actually broken. 640 keeps it unreadable to everyone else, and
> Apache denies the whole directory anyway (step 8).

---

## 8. Apache vhost + SSL

`sudo nano /etc/apache2/sites-available/store-shift.conf`:

```apache
<VirtualHost *:80>
    ServerName shopshift.net
    DocumentRoot /var/www/store-shift

    <Directory /var/www/store-shift>
        Options -Indexes +FollowSymLinks
        # Required: app/.htaccess carries Laravel's rewrite rules, and without
        # them every API call loses its Authorization header and returns 401.
        AllowOverride All
        Require all granted
    </Directory>

    # ---- Everything below keeps the repository's own files off the internet ----

    # The Laravel application. It is inside the web root because app/index.php
    # boots it from ./backend/ — but nothing in it may ever be served. This
    # blocks .env, vendor/, composer.json, every class file. Apache authz does
    # not affect PHP's own filesystem access, so the app still works.
    <Directory /var/www/store-shift/app/backend>
        Require all denied
    </Directory>

    # A clone brings the git history with it. /.git/config can contain a token.
    <DirectoryMatch "/\.git">
        Require all denied
    </DirectoryMatch>

    # Deploy scripts name your paths and process layout.
    <Directory /var/www/store-shift/deploy>
        Require all denied
    </Directory>

    # Docs and build scripts at the repo root.
    <FilesMatch "\.(md|bat|sh|py|lock|example)$">
        Require all denied
    </FilesMatch>

    # Dotfiles anywhere.
    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>

    ErrorLog  ${APACHE_LOG_DIR}/store-shift-error.log
    CustomLog ${APACHE_LOG_DIR}/store-shift-access.log combined
</VirtualHost>
```

```bash
sudo a2ensite store-shift && sudo apache2ctl configtest && sudo systemctl reload apache2
sudo certbot --apache -d shopshift.net
```

Certbot writes the `:443` vhost and a redirect. Choose **"redirect HTTP to
HTTPS"** — Shopify embeds the app in an iframe and will not load it over HTTP.

> The repo also ships `app/backend-htaccess.txt`, a deny-all `.htaccess` for
> hosts with no vhost access. On a VPS the `<Directory>` block above is better:
> it cannot be forgotten during a deploy and cannot be overwritten by a
> `git pull`. Copying it in as well does no harm:
> `cp app/backend-htaccess.txt app/backend/.htaccess`

---

## 9. Restore the database, migrate, seed

```bash
# restore the dump from step 0.2
mysql -u ss_root -p ss_main < ~/ss_main.sql

cd /var/www/store-shift/app/backend
php artisan migrate --force        # applies the new scale indexes
php artisan db:seed --class=AdminSeeder --force
php artisan config:cache
php artisan route:cache
```

> `AdminSeeder` only **creates** plans that do not exist. Prices and limits you
> set at `/app/admin/plans` survive every deploy. To deliberately push code
> values back out: `PLAN_SEED_OVERWRITE=true php artisan db:seed --class=AdminSeeder --force`.

Blank `ADMIN_PASSWORD` out of `.env` once the seed has run, then
`php artisan config:cache` again.

---

## 10. Supervisor — the worker pool

This is what makes a large migration finish in an afternoon instead of overnight.
The engine decides from the shop's measured Shopify budget that it may run up to
8 batches at once; supervisor is the pool that lets that actually happen.

```bash
sudo cp /var/www/store-shift/deploy/supervisor/store-shift-worker.conf \
        /etc/supervisor/conf.d/store-shift-worker.conf
sudo nano /etc/supervisor/conf.d/store-shift-worker.conf
```

Set these three lines for this server:

```ini
command=php /var/www/store-shift/app/backend/artisan queue:work --queue=default --sleep=3 --tries=3 --timeout=600 --max-time=3600
directory=/var/www/store-shift/app/backend
user=www-data
```

`numprocs=8` is right for an 8GB box. On 4GB use `numprocs=4` — each worker
holds a page of WooCommerce products and peaks around 100–150MB.

```bash
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl start store-shift-worker:*
sudo supervisorctl status
```

All eight must read `RUNNING`. If they flap, the log says why:

```bash
tail -50 /var/www/store-shift/app/backend/storage/logs/worker.log
```

> `--timeout=600` must stay **below** `retry_after` (900). Above it, the queue
> hands a still-running job to a second worker — the bug §6 of DEPLOYMENT.md
> exists to warn about.

---

## 11. Crons

```bash
sudo crontab -u www-data -e
```

```cron
*/5 * * * * cd /var/www/store-shift/app/backend && php artisan migration:watchdog >> /var/log/store-shift-watchdog.log 2>&1
0 3 * * *   cd /var/www/store-shift/app/backend && php artisan migration:prune >> /var/log/store-shift-prune.log 2>&1
0 * * * *   cd /var/www/store-shift/app/backend && php artisan billing:sync >> /var/log/store-shift-billing.log 2>&1
```

```bash
sudo touch /var/log/store-shift-watchdog.log /var/log/store-shift-prune.log /var/log/store-shift-billing.log
sudo chown www-data:www-data /var/log/store-shift-*.log
```

**Do not** add `queue:keepalive` here. That cron exists for hosts with no process
manager; with supervisor running it would start a second pool on top of the
first. `QUEUE_AUTO_WORKER=false` in step 6 already tells the app to stay out of
the way — the two together are the belt and the braces.

The watchdog is still required. Supervisor restarts a dead *worker*; the watchdog
recovers a dead *migration* — a batch whose worker was OOM-killed mid-page, a run
whose dispatches were lost, a run whose batches all finished but was never closed
out.

---

## 12. Verify

```bash
curl -I https://shopshift.net/           # 200  landing
curl -I https://shopshift.net/app/       # 200  SPA
curl -I https://shopshift.net/app/admin  # 200  admin login

# every one of these MUST be 403 or 404 — never 200
curl -I https://shopshift.net/app/backend/.env
curl -I https://shopshift.net/app/backend/composer.json
curl -I https://shopshift.net/.git/config
curl -I https://shopshift.net/CLAUDE.md
curl -I https://shopshift.net/deploy/queue-workers.sh
```

End to end:

```bash
cd /var/www/store-shift/app/backend
php artisan tinker --execute="dispatch(function () { logger('worker alive'); }); echo 'queued';"
sleep 5 && tail -5 storage/logs/laravel.log     # should contain "worker alive"
```

If that line appears, the queue, Redis and the worker pool are all working — the
one thing that cannot be checked from a browser.

Then open `/app/admin` → the overview shows **pending jobs**, **failed jobs** and
the worker-pool settings it is actually running with.

---

## 12.1 One command that checks everything

```bash
sudo cp /var/www/store-shift/deploy/healthcheck.sh /usr/local/bin/ss-health && sudo chmod +x /usr/local/bin/ss-health
```

From then on, after every deploy and any time something looks wrong:

```bash
sudo ss-health
```

It checks the five services, the supervisor worker pool, both crons,
`storage` permissions, `QUEUE_AUTO_WORKER`, `retry_after`, queue depth, failed
jobs, stalled batches, the three public URLs, the five paths that must **not** be
public, and disk/RAM. Exit code is non-zero if anything failed, so it also works
from a monitoring cron.

---

## 13. Redeploying after this

```bash
cd /var/www/store-shift
git pull
cd app/backend
composer install --no-dev --optimize-autoloader   # only if composer.lock changed
php artisan migrate --force                       # only if there are new migrations
php artisan config:cache && php artisan route:cache
sudo supervisorctl restart store-shift-worker:*
```

### The restart is not optional ⚠️

PHP holds the old classes in memory for the life of a worker process. A worker
started before the deploy keeps running the **old code** until it hits its
`--max-time` (up to an hour) — so a bug you just fixed goes on happening, on
somebody's live migration, with the new code sitting on disk.

Frontend changes need `git pull` only: the built assets are committed, and their
filenames are content-hashed so there is no cache to bust.

---

## Troubleshooting

| Symptom | Cause |
|---|---|
| 500 on every route | `storage/` not writable → step 7 |
| API 401 everywhere | `AllowOverride All` missing → `app/.htaccess` ignored → `Authorization` header stripped |
| `/app/assets/*` 404 | `DocumentRoot` not pointing at the repo root |
| Migrations stuck *pending* | `sudo supervisorctl status` — pool not running |
| Migrations stuck *processing*, no errors | Watchdog cron missing → `php artisan migration:watchdog --stall=2` |
| Only one batch at a time despite 8 workers | MySQL < 8.0.1 (no `SKIP LOCKED`), or the shop's own Shopify plan caps it — Basic allows 2, Advanced 6, Plus 8 |
| Progress ahead of reality, run then freezes | `retry_after` below a job timeout → step 6 |
| Merchants forced to reinstall; "could not decrypt" | `APP_KEY` changed → restore the old one (step 0.1) |
| Jobs vanish under load | Redis `maxmemory-policy` is evicting → set `noeviction` (step 4) |
| `.env` edit ignored | `php artisan config:cache` |
| Old behaviour after a deploy | Workers still on pre-deploy code → `sudo supervisorctl restart store-shift-worker:*` |
| Merchants never charged | `SHOPIFY_BILLING_TEST` still `true` |

---

## Still outstanding before the App Store listing

These are not server tasks, but nothing above closes them:

- **Protected Customer Data approval** — Partner Dashboard → Distribution (set
  first) → API access requests. Until it is granted, customers **and** orders are
  denied by Shopify. The app now names this explicitly on every failed record
  rather than reporting "No id returned by Shopify".
- **A public WooCommerce test store** for the reviewer.
- **`support@webnday.com`** — verify the mailbox exists; every public page points
  at it.
- **SMTP** — set it at `/app/admin/settings`, not in `.env`. Do **not** rely on
  the VPS sending its own mail: a fresh datacenter IP has no reputation and
  completion emails will land in spam.
