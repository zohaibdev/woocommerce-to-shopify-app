# COMMANDS.md — Store Shift

Operational and development command reference. **Every command here was verified
against this repository** — `composer.json` scripts, `package.json` scripts, the
artisan command signatures in `app/backend/app/Console/Commands/`, `deploy/*` and the
deployment runbooks. Nothing is generic Laravel boilerplate that this project does not
actually use.

- Architecture: **[ARCHITECTURE.md](ARCHITECTURE.md)**
- Orientation: **[PROJECT_CONTEXT.md](PROJECT_CONTEXT.md)**

> **Secrets.** No command below prints or accepts a secret value. Anything touching
> credentials names the *variable*, never the value.

> **Paths.** Backend commands run from `app/backend` unless stated. On the VPS that is
> `/var/www/store-shift/app/backend`; on shared hosting `~/public_html/app/backend`.
> The repo folder, the git remote and the supervisor config file are all named
> `store-shift`. Those are *paths and identifiers*, not branding — do not rename them.

---

## Quick reference

| I need to… | Command |
|---|---|
| Run the whole dev stack | `cd app/backend && composer dev` |
| Run tests | `cd app/backend && php artisan test` |
| Typecheck the frontend | `cd app/frontend && npx tsc -b` |
| Build for production | `build-prod.bat` (from repo root) |
| See why a migration is slow | Admin → Migrations → **Developer log** |
| Restart workers after a deploy | `sudo supervisorctl restart store-shift-worker:*` |
| Un-stick a frozen migration | `php artisan migration:watchdog` |
| Reconcile billing with Shopify (drifted shops, queued) | `php artisan billing:sync` |
| Reconcile one shop's billing now | `php artisan billing:sync --shop=store.myshopify.com` |
| Check everything is running (VPS) | `sudo ss-health` |

---

## Local Development

### Full stack in one command

```bash
cd app/backend && composer dev
```

- **Purpose**: runs `php artisan serve`, `queue:listen`, `pail` (live logs) and
  `npm run dev` concurrently.
- **When**: normal day-to-day development.
- **Expected**: four coloured log streams; app on `http://127.0.0.1:8000`, Vite on `:5173`.
- **Common failure**: `npx concurrently` missing → run `npm install` in `app/frontend` first.
- **Recover**: Ctrl-C kills all four (`--kill-others`).

> Note this uses `queue:listen`, not `queue:work` — it reloads code between jobs, so you
> do **not** have to restart it after every edit. Production uses `queue:work`, which does.

### Individual services

```bash
cd app/backend  && php artisan serve --host=127.0.0.1 --port=8000
cd app/backend  && php artisan queue:work
cd app/frontend && npm run dev
```

---

## Backend

```bash
cd app/backend && composer install
```
Installs PHP dependencies. Add `--no-dev --optimize-autoloader` on production.

```bash
php artisan about
```
Environment summary — Laravel version, PHP version, **cache/queue/session drivers**.
Fastest way to confirm which queue driver is actually live.

```bash
composer setup
```
First-time bootstrap: install, create `.env` from the example, generate `APP_KEY`,
migrate, `npm install`, `npm run build`. **Only for a fresh checkout** — it runs
`migrate --force`.

---

## Frontend

```bash
cd app/frontend
npm install        # dependencies
npm run dev        # Vite dev server (:5173)
npm run build      # tsc -b && vite build  → dist/
npm run lint       # oxlint
npx tsc -b         # typecheck only, no build
```

`vite.config.ts` sets `base` to `/app/` in production and `/` in dev. `npm run build`
runs the typecheck first, so a type error fails the build.

---

## Database

```bash
php artisan migrate                 # apply pending migrations
php artisan migrate --force         # production (skips the confirmation prompt)
php artisan migrate:status          # what has and has not run
php artisan migrate --pretend       # print the SQL without running it
```

- **Common failure**: `database disk image is malformed` — the local SQLite file is
  corrupt. Tests are unaffected (they use their own DB).
- **Recover (local only)**: delete `database/database.sqlite`, recreate it and re-run
  `php artisan migrate`. **Never** do this on production.

```bash
php artisan db:seed --class=AdminSeeder --force
```
Creates the super-admin user and any **missing** plans. It does **not** overwrite plans
an admin has edited — that is deliberate; the deploy runbook runs it every release.
To deliberately push code values back out, set `PLAN_SEED_OVERWRITE=true` first.

---

## Migrations (the product feature, not schema)

```bash
php artisan migration:watchdog
php artisan migration:watchdog --stall=10
```
- **Purpose**: revives batches stuck in `processing` past the stall cutoff, restarts a
  run whose dispatches were lost, re-queues failed jobs.
- **When**: a migration is frozen; also runs automatically every 5 minutes.
- **Expected**: a summary of what it revived, or silence when nothing is stuck.
- **Safe to run any time** — it only ever looks at persisted state.

```bash
php artisan migration:prune
php artisan migration:prune --days=90 --debug-days=14
```
- **Purpose**: deletes activity logs of **finished** runs. `--debug-days` prunes per-job
  `debug` lines sooner — there is one per queue job, so they dominate the table.
- **When**: daily cron. Safe manually.
- **Never touches** a run that is still in flight.

---

## Queue

```bash
php artisan queue:work --queue=default --sleep=3 --tries=3 --timeout=600 --max-time=3600
```
- **Purpose**: the production worker invocation (matches `queue-workers.sh` and the
  supervisor config).
- **`--timeout` must stay below `retry_after` (900)** in `config/queue.php`, or the queue
  hands a still-running job to a second worker and every record on that page runs twice.

```bash
php artisan queue:listen        # dev only — reloads code between jobs
php artisan queue:restart       # ask all workers to exit gracefully after the current job
php artisan queue:failed        # list failed jobs
php artisan queue:retry all     # re-queue every failed job
php artisan queue:retry <id>    # re-queue one
php artisan queue:flush         # DELETE all failed jobs — destructive, cannot be undone
php artisan queue:keepalive     # this project's own keeper (see below)
```

`queue:keepalive` is a **custom command** (`app/Console/Commands/QueueKeepAlive.php`).
It starts self-managed workers when there is queued work and free capacity. It runs every
minute from the scheduler. **Do not add it to the VPS crontab** — with supervisor running
it starts a second pool.

---

## Workers

### Which mechanism is running?

Three exist and **must not be combined**:

| Mechanism | Config | Set `QUEUE_AUTO_WORKER` |
|---|---|---|
| supervisor (`numprocs=8`) | `deploy/supervisor/store-shift-worker.conf` | `false` |
| cron keeper (default 4) | `deploy/queue-workers.sh` | `false` |
| app self-starter | `QUEUE_AUTO_WORKERS` | `true` (default) |

### Check status and count

```bash
sudo supervisorctl status store-shift-worker:*        # supervisor
pgrep -fc "artisan queue:work"                        # actual live process count
ps -eo pid,etime,args | grep "[a]rtisan queue:work"   # with start times
```

**How many are actually working a migration** — the process count alone will not tell
you, because idle workers look identical to busy ones:

> Admin → Migrations → **Developer log** → *Workers*. Every job records its `host:pid`.
> If fewer processes are busy than are running, the run is **ceilinged, not starved** —
> compare against the *Concurrency* section on the same page.

### Restart

```bash
sudo supervisorctl restart store-shift-worker:*     # supervisor
php artisan queue:restart                           # any mechanism: graceful
```

**You must restart workers after any backend code change** — PHP holds the old classes
in memory for the life of the process.

### Restarting safely during an active migration

`php artisan queue:restart` is the safe one: workers finish the job in hand and then exit.
Nothing is lost either way — a killed batch resumes from `processed_offset` and at worst
replays one idempotent record — but a graceful restart avoids the watchdog having to
notice.

```bash
sudo supervisorctl stop store-shift-worker:*       # stop (migration pauses, resumes on start)
sudo supervisorctl start store-shift-worker:*
```

`stopwaitsecs=610` in the supervisor config gives a batch time to finish rather than
being killed mid-item.

### Diagnosing stuck jobs

```bash
php artisan migration:watchdog          # first thing to try
php artisan queue:failed                # anything given up on?
```
Then the developer log for where the time is going. See **Troubleshooting**.

---

## Supervisor

Config: `deploy/supervisor/store-shift-worker.conf` (`numprocs=8`).

```bash
sudo cp deploy/supervisor/store-shift-worker.conf /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start store-shift-worker:*
sudo supervisorctl status
```

Edit `numprocs` then `reread && update` to change the pool size.
Replace `USER` and the paths in the file before installing.

**Horizon is not used** — the queue is the database driver.

---

## Cron

### VPS (from `DEPLOYMENT-VPS.md` §11)

```bash
sudo crontab -u www-data -e
```

```cron
*/5 * * * * cd /var/www/store-shift/app/backend && php artisan migration:watchdog >> /var/log/store-shift-watchdog.log 2>&1
0 3 * * *   cd /var/www/store-shift/app/backend && php artisan migration:prune    >> /var/log/store-shift-prune.log    2>&1
0 * * * *   cd /var/www/store-shift/app/backend && php artisan billing:sync       >> /var/log/store-shift-billing.log  2>&1
```

```bash
sudo touch /var/log/store-shift-watchdog.log /var/log/store-shift-prune.log /var/log/store-shift-billing.log
sudo chown www-data:www-data /var/log/store-shift-*.log
```

### Shared hosting (no process manager)

```cron
* * * * * /bin/bash ~/queue-workers.sh >> ~/worker-cron.log 2>&1
0 * * * * cd ~/public_html/app/backend && php artisan billing:sync >> ~/billing-sync.log 2>&1
```
Keep `queue-workers.sh` in `$HOME`, **not** under `public_html` — anything in the web
root is downloadable and that file names your paths and process layout.
Tune with `MIGRATION_WORKERS` (default 4).

### Verify and troubleshoot cron

```bash
sudo crontab -u www-data -l              # what is installed
tail -f /var/log/store-shift-watchdog.log
grep CRON /var/log/syslog | tail -20     # did cron fire at all?
```

- **Common failure**: cron has a minimal `PATH` and cannot find `php` → use the absolute
  binary path.
- **Changing the schedule safely**: edit with `crontab -e` (validates before saving) and
  watch the log for one interval before walking away.

---

## Logs

```bash
tail -f storage/logs/laravel.log                    # application
tail -f storage/logs/worker.log                     # workers (both keeper and supervisor)
php artisan pail                                    # live tailer (dev)
tail -f /var/log/store-shift-watchdog.log           # watchdog cron
sudo tail -f /var/log/apache2/error.log             # web server (VPS)
```

Searching:

```bash
grep -i "migration" storage/logs/laravel.log | tail -50
grep -i "shopify" storage/logs/laravel.log | tail -50
grep -i "WooCommerce refused" storage/logs/laravel.log     # 403/401 on a count
grep -iE "throttl|too many attempts" storage/logs/laravel.log
```

**The best migration diagnostics are not in the log files.** Per-run detail lives in the
database and is exposed at:

- Merchant: `GET /api/migrations/{id}/logs`, or the downloadable report.
- Developer: **Admin → Migrations → Developer log**, or
  `GET /api/admin/migrations/{id}/debug-log` — where the time went (Shopify vs
  WooCommerce vs throttle sleep), which workers ran, chunk states, and a findings section.

---

## Cache

```bash
php artisan optimize:clear     # everything below at once
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache       # production: rebuild the config cache
php artisan route:cache
```

- **`php artisan config:cache` after ANY `.env` change** — otherwise the change has no effect.
- **`php artisan view:clear` after adding or editing a Blade override** (e.g. the mail header).
- After moving folders, `rm bootstrap/cache/*.php` then re-cache — a stale bootstrap cache
  is a 500 on every route.

---

## Configuration

| File | Holds |
|---|---|
| `config/migration.php` | Every migration knob: page size, concurrency ceilings, throttle ladder, debug log, image/metafield defaults |
| `config/queue.php` | Driver, `retry_after`, `auto_worker`, `auto_workers` |
| `config/services.php` | Shopify API key/secret/version/app URL |
| `.env` / `.env.production` | Environment (**never commit**) |
| `.env.example`, `.env.production.example` | Documented templates |

```bash
php artisan about                                  # which drivers are live
php artisan tinker --execute="dump(config('migration.entity_concurrency'));"
```

---

## Deployment

### VPS

```bash
cd /var/www/store-shift
git pull

cd app/backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --class=AdminSeeder --force

cd ../frontend
npm ci
npm run build

cd ../backend
php artisan optimize:clear
php artisan config:cache
php artisan route:cache

sudo supervisorctl restart store-shift-worker:*
sudo crontab -u www-data -l          # verify crons survived
sudo ss-health                       # health check
```

### Shared hosting

Run `build-prod.bat` locally (builds the frontend, copies the SPA shell and assets, and
produces `store-shift-deploy.zip` with **forward-slash** entries — PowerShell's
`Compress-Archive` writes backslashes and breaks `/assets/*` on Linux). Upload, then on
the server run the migrate / seed / config:cache steps above.

### Post-deploy checklist

1. `php artisan migrate:status` — nothing pending
2. `php artisan about` — expected drivers
3. Workers restarted (**mandatory** — PHP caches classes)
4. Crons still installed
5. `backend/.htaccess` deny-all is in place (source must not be publicly readable)
6. Start a small test migration

---

## Troubleshooting

| Symptom | Diagnose | Fix |
|---|---|---|
| **Migration stuck at "processing"** | `php artisan queue:failed`; developer log → chunk states | `php artisan migration:watchdog` |
| **Workers not processing** | `pgrep -fc "artisan queue:work"` = 0 | Start supervisor / check the cron / confirm `QUEUE_AUTO_WORKER` |
| **Only some workers active** | Developer log → *Workers* vs *Concurrency* | Usually **correct** — orders are ceilinged per Shopify tier. See ARCHITECTURE §8 |
| **Queue backlog** | `php artisan queue:failed`, worker count | Raise the pool (`numprocs` / `MIGRATION_WORKERS`), or accept the Shopify ceiling |
| **Failed jobs** | `php artisan queue:failed` | `php artisan queue:retry all`; the watchdog also auto-retries (max 3 per job) |
| **WooCommerce 401** | `grep "rejected the API key" storage/logs/laravel.log` | Credentials genuinely invalid — merchant reissues keys |
| **WooCommerce 403** | `grep "was refused" storage/logs/laravel.log` | **Not credentials.** Security plugin / WAF / rate limiter blocking `/wp-json/`. Allowlist for the migration |
| **Shopify throttling** | Developer log → throttle seconds as % of job time | Expected. >20% on orders is Shopify's limit, not a bug |
| **Slow order migration** | Developer log → *Where the time went* | If throttle-dominated it is Shopify's ceiling. If WooCommerce-dominated it is the merchant's host. See ARCHITECTURE §7 |
| **Incorrect ETA** | Is `basis` `plan` or `progress`? | `plan` = not enough samples yet; it self-corrects per entity within ~15 min |
| **Stale status in the UI** | Frontend polls every 3s | Check the API responds; the DB is the source of truth — never "fix" by reloading |
| **Merchant sees the install screen** | Is `?host`/`?shop` on the first load? | `shopContext.ts` captures it in `main.tsx`; a rebuild may be needed |
| **"Missing session token"** | App Bridge loaded? ad blocker? | Open from the Shopify admin, not a bookmark |
| **500 on every route** | `tail storage/logs/laravel.log` | Stale bootstrap cache → `rm bootstrap/cache/*.php` and re-cache; or the two `index.php` files were swapped |

---

## Emergency Operations

> Read the whole row before running anything here.

**Stop all migration work immediately**
```bash
sudo supervisorctl stop store-shift-worker:*
```
Nothing is lost. Batches resume from `processed_offset` when workers restart.
Also disable the keeper cron if one is installed, or it will start workers again.

**Cancel one merchant's run** — use the app (`POST /api/migrations/{id}/cancel`).
Jobs check the status cooperatively and hand their chunk back. Do **not** delete rows.

**Purge the failed-jobs table** (destructive, unrecoverable)
```bash
php artisan queue:failed        # look first
php artisan queue:flush
```

**Runaway workers**
```bash
pkill -f "artisan queue:work"
```
Last resort. Kills mid-job; the affected batches resume from their last checkpoint.
Prefer `php artisan queue:restart`.

**A poison job looping** — the watchdog auto-retries a failed job at most **3 times**
(cache-counted) and then leaves it for a human. Find it with `php artisan queue:failed`
and inspect the payload before retrying again.

---

## Not applicable to this project

Listed so nobody wastes time looking:

- **`php artisan horizon:*`** — Horizon is not installed; the queue is the database driver.
- **`php artisan schedule:work`** — the scheduler is driven by the crons above.
- **`php artisan storage:link`** — deliberately avoided; the email logo is streamed by a
  route so no symlink is needed.
- **Multiple queue names** — everything uses `default`.

**UNKNOWN**: whether supervisor and the crons are currently installed on the live server.
The repository carries the configuration; the running state can only be confirmed on the
box with `sudo ss-health`.
