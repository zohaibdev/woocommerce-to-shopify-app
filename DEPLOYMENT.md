# Deploying Store Shift to Hostinger (shared hosting)

Landing page: `https://shopshift.net/`
App: `https://shopshift.net/app/`

> **On a VPS with root? Use [DEPLOYMENT-VPS.md](DEPLOYMENT-VPS.md) instead.**
> It covers Ubuntu 24.04 + Apache + MySQL 8 + Redis + a supervisor worker pool
> from a bare server, and it deploys by `git clone` rather than by uploading a
> zip. The difference that matters is the worker pool: a large catalogue takes an
> afternoon there and overnight here.

---

## 1. Server folder layout

```
public_html/
├── index.html              ← landing page (domain root)
├── favicon.svg  icons.svg
├── assets/images/          ← landing page screenshots + logo (used by index.html)
│
└── app/                    ← the whole application
    ├── index.php           ← Laravel front controller (paths point to ./backend/)
    ├── .htaccess           ← Laravel rewrite rules
    ├── favicon.svg  icons.svg
    ├── assets/             ← built JS/CSS  (/app/assets/...)
    └── backend/            ← full Laravel app
        ├── .htaccess       ← DENY ALL (protects .env, vendor, app ...)
        ├── app/  bootstrap/  config/  database/  routes/  vendor/
        ├── resources/spa/index.html   ← SPA shell served by Laravel
        ├── storage/        ← writable (775)
        └── .env
```

Laravel detects the `/app` subdirectory automatically from `SCRIPT_NAME`, so routes
stay declared without a prefix — `/app/api/...` and `/app/admin` just work.

---

## 2. Upload

Unzip **`store-shift-deploy.zip`** into `public_html/`. It contains:

```
index.html          favicon.svg   icons.svg
assets/images/*
app/index.php       app/.htaccess
app/assets/*        app/favicon.svg  app/icons.svg
app/backend-htaccess.txt   ← rename to backend/.htaccess (see §3)
```

Then upload the Laravel app itself into `public_html/app/backend/`
(everything from the local `backend/` folder **except** `public/`).

---

## 3. Protect the backend folder ⚠️

`backend/` sits inside the web root, so it must be blocked by Apache:

1. Move `app/backend-htaccess.txt` → `app/backend/.htaccess`
2. Rename it to exactly **`.htaccess`** (leading dot, no `.txt`)

> File Manager hides dotfiles — enable **Show hidden files** first.

---

## 4. Configure

```bash
cd ~/public_html/app/backend
mv .env.production .env
chmod 600 .env
chmod -R 775 storage bootstrap/cache
```

Key values (already set in `.env.production`):

```ini
APP_URL=https://shopshift.net/app
SHOPIFY_APP_URL=https://shopshift.net/app
APP_DEBUG=false
DB_DATABASE=ss_main
DB_USERNAME=ss_root
ADMIN_PASSWORD=            # ← set before seeding
```

---

## 5. Install & migrate (SSH)

```bash
cd ~/public_html/app/backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --class=AdminSeeder --force
php artisan config:cache
php artisan route:cache
```

> **Plans are seeded, not overwritten.** The seeder only creates plans that do
> not exist yet, so prices and limits you set at `/app/admin/plans` survive every
> deploy. It used to `updateOrCreate`, which silently reset them on each release.
> To deliberately push a pricing change out from the code instead:
>
> ```bash
> PLAN_SEED_OVERWRITE=true php artisan db:seed --class=AdminSeeder --force
> ```

---

## 6. Queue workers (REQUIRED)

Without these, migrations stay stuck at *pending* forever.

### 6.0 Which option to use

| Host | Use |
|---|---|
| VPS / anything with root | `deploy/supervisor/store-shift-worker.conf` |
| Shared hosting (Hostinger) | `deploy/queue-workers.sh` from cron |

**Supervisor** — edit `USER` in the file, then:

```bash
sudo cp deploy/supervisor/store-shift-worker.conf /etc/supervisor/conf.d/ && sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start store-shift-worker:*
```

**Shared hosting** — upload `deploy/queue-workers.sh` to your **home directory**,
not into `public_html`. Anything under the web root is downloadable, and this
file names your paths and process layout:

```bash
chmod +x ~/queue-workers.sh
```

Then hPanel → **Advanced → Cron Jobs** → every minute (`* * * * *`):

```bash
/bin/bash ~/queue-workers.sh >> ~/worker-cron.log 2>&1
```

The script starts workers only up to the target count (default 4, set
`MIGRATION_WORKERS` to change it), so running it every minute is how a dead
worker gets replaced. It finds Laravel on its own; if your layout differs, set
`BACKEND_DIR=/full/path/to/app/backend` in the cron line.

### 6.0.1 Why not the old one-line cron ⚠️

The previous instruction here was:

```
php artisan queue:work --stop-when-empty --max-time=55
```

It works, but it is the single biggest reason a large catalogue took all night:

- **One worker.** `ShopifyPlan` reads the shop's real Shopify budget and decides
  it may run up to 8 batches at once, and `MigrationRunner::advance()` dispatches
  8 jobs — but a single worker runs them one after another. At roughly 3s per
  product (`productSet` is synchronous and waits for Shopify to download every
  image), 50k products is **~42 hours serial vs ~5–6 hours across 8 workers**.
- **No `--timeout`,** so the worker default of 60s applied to any job that did
  not set its own. That is exactly how `StartMigrationJob` used to get killed
  while counting a big store.
- **`--stop-when-empty` exits mid-run.** The queue looks empty every time the
  only outstanding job is the one being worked, so the worker quit and the run
  idled until the next cron minute.

Never set the worker's `--timeout` at or above `DB_QUEUE_RETRY_AFTER` (900) —
see 6.2.

### 6.1 Watchdog cron (REQUIRED)

Second cron, every 5 minutes (`*/5 * * * *`). This is the guarantee that a run can
never freeze: it resumes any migration that has stopped making progress (worker
OOM-killed, host rebooted mid-job, a lost queue row) and closes out runs whose
batches all finished.

```bash
cd ~/public_html/app/backend && php artisan migration:watchdog
```

> A batch is only considered stalled after 10 minutes without progress. Override
> with `--stall=<minutes>` (minimum 2) to recover a stuck run sooner.

### 6.2 Prune cron (recommended)

Third cron, once a day (`0 3 * * *`). A large run writes a log line per failed or
warned record, and those rows are worth nothing months later — this deletes the
activity logs of migrations that finished long ago. Mapping rows in
`migration_items` are **never** touched: they are what stops a re-run creating
duplicates.

```bash
cd ~/public_html/app/backend && php artisan migration:prune
```

> Keeps 90 days by default. Override with `--days=<n>`.

### 6.3 All three crons at a glance

| Cron | Schedule | Command | Required? |
|---|---|---|---|
| Queue workers | `* * * * *` | `/bin/bash ~/queue-workers.sh >> ~/worker-cron.log 2>&1` | **Yes** — nothing migrates without it |
| Watchdog | `*/5 * * * *` | `cd ~/public_html/app/backend && php artisan migration:watchdog` | **Yes** — nothing recovers without it |
| Prune | `0 3 * * *` | `cd ~/public_html/app/backend && php artisan migration:prune` | Recommended |
| Billing sync | `0 * * * *` | `cd ~/public_html/app/backend && php artisan billing:sync` | **Yes** — Shopify sends no webhook on a routine renewal; without it renewals, expiries and missed cancellations are never seen |

> Do **not** also add Laravel's `schedule:run` cron. `routes/console.php`
> registers these on the scheduler, so running both would just start the same
> command twice — harmless (they are idempotent) but pointless noise. Pick the
> direct commands above.

### 6.3.1 No worker cron possible? `queue:keepalive` every minute

If the host will not give you a per-minute cron for `queue-workers.sh` — or
disables `pgrep`/`nohup` — leave `QUEUE_AUTO_WORKER=true` and run this instead:

```bash
cd ~/public_html/app/backend && php artisan queue:keepalive
```

| Cron | Schedule | Required? |
|---|---|---|
| Keepalive | `* * * * *` | Only when you are NOT running `queue-workers.sh` or supervisor |

It starts up to `QUEUE_AUTO_WORKERS` detached workers when there is queued work
and free capacity, and does nothing otherwise (two queries).

**Why every minute matters.** A self-started worker lives 55 seconds. The app
also starts workers off ordinary API traffic, which covers the case where the
merchant is watching the page — but migrations run for hours and merchants are
explicitly told they may close the tab. Before this cron existed the only other
heartbeat was the five-minute watchdog, so an unwatched run got **55 seconds of
work out of every 300** — an 18% duty cycle that turned a 14-hour migration into
a three-day one, with nothing anywhere explaining the slowdown.

> ⚠️ Run **either** `queue-workers.sh`/supervisor **or** this — never both. Each
> maintains its own pool, so together you get twice the workers you sized for.
> Whenever a process manager is running, set `QUEUE_AUTO_WORKER=false`.

### 6.4 `retry_after` must exceed the job runtime ⚠️

`config/queue.php` sets `retry_after` to **900s**. It was 90s, which was shorter
than a full batch — so the queue declared the job dead mid-run and started a
second copy of it. That replay double-counted every item and, once retries ran
out, killed the job chain and froze the migration at *processing* with no error.
Never set `DB_QUEUE_RETRY_AFTER` below ~600.

---

## 7. Shopify Partner Dashboard

| Field | Value |
|---|---|
| App URL | `https://shopshift.net/app` |
| Allowed redirection URL | `https://shopshift.net/app/api/auth/callback` |

> ⚠️ **App URL is the app ROOT, not `/api/auth`.** Sending it to the OAuth
> endpoint is the old install-flow habit, and it breaks client-side navigation:
> the Shopify admin derives the in-app path by stripping the App URL off the
> current URL, so a move to `/app/woocommerce` matched nothing, the admin's own
> router computed `undefined`, and the page died with
> "Cannot read properties of undefined (reading 'trim')" — thrown from inside
> Shopify's bundle, not ours. The first load looked fine; every nav click after
> it crashed. Managed install (`use_legacy_install_flow = false`) does the scope
> grant on its own and simply loads the app here.

GDPR webhooks:

```
https://shopshift.net/app/api/webhooks/customers-data-request
https://shopshift.net/app/api/webhooks/customers-redact
https://shopshift.net/app/api/webhooks/shop-redact
```

---

## 8. Verify

```bash
curl -I https://shopshift.net/                      # 200  landing page
curl -I https://shopshift.net/app/                  # 200  SPA
curl -I https://shopshift.net/app/assets/index-Be5bzpko.js   # 200
curl -I https://shopshift.net/app/admin             # 200  admin login
curl -i  "https://shopshift.net/app/api/auth?shop=YOUR-STORE.myshopify.com"  # 302 → shopify

# these MUST NOT return 200
curl -I https://shopshift.net/app/backend/.env
curl -I https://shopshift.net/app/backend/composer.json
```

Install URL:

```
https://shopshift.net/app/api/auth?shop=YOUR-STORE.myshopify.com
```

Admin panel: `https://shopshift.net/app/admin`

---

## 9. Redeploying after a change

Run `build-prod.bat` locally first. It type-checks, builds, copies the assets and
SPA shell into `app/`, **runs the backend test suite**, and only then writes
`store-shift-deploy.zip`. A red test stops the build before anything is packed.

| Changed | Do |
|---|---|
| Frontend | upload `app/assets/` (delete the old one first — filenames are hashed) + `app/backend/resources/spa/index.html` |
| Backend PHP | upload the changed files → `php artisan route:clear && php artisan config:cache` |
| `.env` | edit → `php artisan config:cache` (otherwise the change is ignored) |
| DB schema | `php artisan migrate --force` |
| Queue/worker scripts | re-upload `~/queue-workers.sh`, then kill the running workers so they restart on the new settings |

### 9.1 After ANY backend deploy ⚠️

```bash
pkill -f "artisan queue:work"
```

PHP holds the old classes in memory for the life of the worker process, so a
worker started before the deploy keeps running the **old code** until it hits its
`--max-time` (up to an hour). The cron restarts them within a minute. On
supervisor: `sudo supervisorctl restart store-shift-worker:*`.

### 9.2 Deploying the scale-hardening release

This release adds a schema change and new worker configuration, so the order
matters:

```bash
# 1. upload app/assets/, app/backend/resources/spa/index.html, and the changed
#    backend PHP files, then:
cd ~/public_html/app/backend
php artisan migrate --force        # adds migration_batches.source_ids
php artisan config:cache
php artisan route:cache

# 2. restart workers onto the new code
pkill -f "artisan queue:work"
```

Then set up the crons in §6.3 — the queue-worker one **replaces** the old
single-worker line, it is not added alongside it.

Nothing here needs a re-seed: `AdminSeeder` no longer overwrites existing plans,
so the Pro plan's shared 20,000-record budget only applies to installs that do
not have a `pro` row yet. To move an existing install onto it, edit the plan at
`/app/admin/plans` and set `limits` to `{"total": 20000, "metafields": true}`, or
run the seeder once with `PLAN_SEED_OVERWRITE=true` (§5) — which resets **every**
plan to the code values, so only do that if that is what you want.

---

## Troubleshooting

| Symptom | Cause |
|---|---|
| `/app/` 404 | `app/index.php` paths not pointing at `./backend/` |
| `/app/assets/*` 404 | Zip extracted as `assets\file.js` — rebuild with `build-prod.bat` |
| Blank page / 500 | `storage/` not writable → `chmod -R 775 storage` |
| API 401 everywhere | `.htaccess` missing → `Authorization` header stripped |
| Migration stuck *pending* | Queue cron (§6) not running |
| Migration stuck *processing*, no errors | Watchdog cron (§6.1) not running — run `php artisan migration:watchdog --stall=2` |
| Progress % higher than reality | Stale deploy: needs `retry_after` ≥ 600 (§6.4) + `php artisan config:cache` |
| Migration marked *failed* seconds after starting | Planning could not read the store. Check the activity log for the WooCommerce error — expired API keys are the usual cause |
| Run ends *completed with errors* but no failed items listed | Whole batches could not be read. The details page names them; "Retry failed items" re-runs them (§6 workers must be up) |
| Old behaviour after a deploy | Workers still running pre-deploy code — `pkill -f "artisan queue:work"` (§9.1) |
| Only one batch runs at a time | Still on the old single-worker cron. Replace it with §6.3's `~/queue-workers.sh` line |
| Plan limits reset after a deploy | Someone ran the seeder with `PLAN_SEED_OVERWRITE=true` (§5) |
| `.env` edit ignored | Run `php artisan config:cache` |
| `backend/composer.json` returns 200 | `backend/.htaccess` missing or still named `.txt` |
