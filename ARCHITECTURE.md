# ARCHITECTURE.md — Shop Shift

Complete technical architecture. Written for a developer who has never seen this
codebase and has to safely change it.

Everything here was read out of the repository. Anything not verifiable from the code
is marked **UNKNOWN**.

- Orientation and business rules: **[PROJECT_CONTEXT.md](PROJECT_CONTEXT.md)**
- Commands to run: **[COMMANDS.md](COMMANDS.md)**

---

## Table of contents

1. [Request lifecycle](#1-request-lifecycle)
2. [Folder structure](#2-folder-structure)
3. [Entry points](#3-entry-points)
4. [Routes](#4-routes)
5. [Database](#5-database)
6. [Migration System Deep Dive](#migration-system-deep-dive)
7. [Per-entity migration behaviour](#7-per-entity-migration-behaviour)
8. [Rate limits, throttling and concurrency](#8-rate-limits-throttling-and-concurrency)
9. [Queue and workers](#9-queue-and-workers)
10. [Scheduled tasks and cron](#10-scheduled-tasks-and-cron)
11. [Frontend](#11-frontend)
12. [Authentication](#12-authentication)
13. [Email](#13-email)
14. [Static pages](#14-static-pages)
15. [Environment variables](#15-environment-variables)
16. [Important files](#16-important-files)
17. [Common change guide](#17-common-change-guide)

---

## 1. Request lifecycle

```
Browser (Shopify admin iframe)
   │  Authorization: Bearer <App Bridge session token>
   ▼
app/index.php  (production front controller)
   ▼
routes/api.php ──► shopify.session middleware (AuthenticateSessionToken)
   │                 └─ verifies JWT, exchanges it for a fresh offline token
   ▼
Controller  ──► validates, writes DB rows, DISPATCHES a job, returns immediately
   │
   │  (KeepQueueAlive middleware runs in terminate(), after the response,
   │   and may start a detached worker — see §9)
   ▼
jobs table (database queue driver)
   ▼
queue:work process ──► StartMigrationJob → PlanBatchesJob → ProcessBatchJob × N
   │                       │
   │                       ├─ WooCommerceClient  (read source)
   │                       ├─ transform in the Migrator
   │                       └─ ShopifyClient      (GraphQL write)
   ▼
migration_items / migration_entities / migration_batches / migration_logs
   ▲
   │  polled every 3s
Frontend MigrationDetails.tsx
```

**The controller never migrates anything.** It only enqueues and reports.

---

## 2. Folder structure

```
store-shift/
├── index.html, privacy/, terms/, refund/, contact/, developers/   static site
├── site.css, logo.svg, favicon.svg                                shared assets
├── CLAUDE.md, SPECIFICATION.md, DEPLOYMENT.md, DEPLOYMENT-VPS.md
├── PROJECT_CONTEXT.md, PROJECT-CONTEXT.json, ARCHITECTURE.md, COMMANDS.md
├── build-prod.bat                       production build + zip
├── shopify.app.toml                     Shopify CLI app config
├── deploy/
│   ├── supervisor/store-shift-worker.conf   VPS worker pool (numprocs=8)
│   ├── queue-workers.sh                     shared-hosting worker keeper (cron)
│   ├── healthcheck.sh                       one-shot post-deploy health check
│   └── make-zip.py                          forward-slash-safe zip builder
└── app/                                 ← deployable web root
    ├── index.php                        PRODUCTION front controller
    ├── assets/                          built JS/CSS (generated)
    ├── favicon.svg
    ├── frontend/                        React source
    │   ├── index.html                   dev shell (App Bridge bootstrap)
    │   ├── vite.config.ts               base = /app/ in prod, / in dev
    │   ├── public/favicon.svg
    │   └── src/
    │       ├── main.tsx  App.tsx  ErrorBoundary.tsx
    │       ├── shopContext.ts  navigation.ts  duration.ts  settings.ts
    │       ├── api/client.ts
    │       ├── components/SecurityPluginWarning.tsx
    │       ├── pages/            Dashboard, NewMigration, MigrationDetails,
    │       │                     MigrationHistory, WooCommerceConnection,
    │       │                     Pricing, Settings, Support, InstallPrompt
    │       └── admin/
    │           ├── AdminApp.tsx  api.ts
    │           └── pages/        AdminOverview, AdminStores, AdminStoreDetail,
    │                             AdminMigrations, MigrationDebugPanel,
    │                             AdminErrors, AdminPlans, AdminTickets,
    │                             AdminContent, AdminSettings, AdminLogin
    └── backend/                         Laravel application
        ├── public/index.php             LOCAL dev front controller
        ├── artisan
        ├── bootstrap/app.php            routing + middleware aliases
        ├── routes/  api.php  web.php  console.php
        ├── config/  migration.php  queue.php  services.php  ...
        ├── database/migrations/  database/seeders/
        ├── resources/spa/index.html     built SPA shell (NOT in public/)
        ├── resources/views/vendor/mail/html/header.blade.php
        ├── storage/logs/                laravel.log, worker.log
        ├── tests/Feature/               382 tests
        └── app/
            ├── Migration/               ← THE CORE
            │   ├── Contracts/Migrator.php
            │   ├── AbstractMigrator.php
            │   ├── MigrationRunner.php
            │   ├── MigratorRegistry.php
            │   ├── MigrationItemResult.php
            │   ├── DescriptionImages.php  ImageSizeResolver.php
            │   ├── MetafieldMapper.php    SeoMeta.php
            │   ├── Concerns/ShedsRejectedFields.php
            │   └── Migrators/          Product, Category, Customer, Order,
            │                           Coupon, Blog, Article, Review,
            │                           Attribute*, ProductVariation*, Media*
            │                           (* = registered no-ops)
            ├── Jobs/                    Start, PlanBatches, ProcessBatch,
            │                            Finalize, RetryFailedItems
            ├── Services/                ShopifyClient, ShopifyOAuth, ShopifyPlan,
            │                            ShopifyTokenService, ShopifyBilling,
            │                            ShopifyWebhookRegistrar, SessionToken,
            │                            WooCommerceClient, MailSettings,
            │                            Branding, SupportNotifier
            │                            └── Exceptions/ ShopifyThrottled,
            │                                            WooCommerceUnavailable
            ├── Support/                 MigrationRunner helpers — see §16
            ├── Http/Controllers/        + Admin/ + Auth/
            ├── Http/Middleware/         AuthenticateSessionToken,
            │                            AuthenticateAdmin, KeepQueueAlive
            ├── Console/Commands/        MigrationWatchdog, PruneMigrationData,
            │                            QueueKeepAlive, SyncWebhooks
            ├── Models/                  16 models
            └── Mail/                    4 mailables
```

**Not present** (verified): `app/Repositories`, `app/Events`, `app/Listeners`,
`app/frontend/src/hooks`. There is no repository pattern and no event system.

---

## 3. Entry points

| File | Role | Warning |
|---|---|---|
| `app/index.php` | Production front controller | Paths point at `./backend/` |
| `app/backend/public/index.php` | Local dev front controller | Paths use `../` |
| `routes/web.php` | Serves the SPA shell for any non-`/api` path | Catch-all `^(?!api).*$` |
| `app/frontend/src/main.tsx` | Frontend bootstrap | Calls `shopContext.capture()` **before** React mounts |

---

## 4. Routes

`routes/api.php`. Public → session-token group → admin group.

**Public**
```
GET  /api/auth, /api/auth/callback          Shopify OAuth
GET  /api/billing/callback                  Shopify billing return
GET  /api/branding/logo                     email logo (mail clients have no session)
POST /api/contact                           throttle:5,60
POST /api/webhooks/app-uninstalled
POST /api/webhooks/customers-data-request | customers-redact | shop-redact   (GDPR, HMAC-verified)
```

**Merchant — `middleware('shopify.session')`**
```
GET  /api/shop
GET  /api/woocommerce/connection            stored connection, no network call
POST /api/woocommerce/connect | test
GET  /api/woocommerce/preview               counts + plan verdicts + estimate
GET|POST /api/billing, /api/billing/subscribe, /api/billing/cancel
GET  /api/content
GET|POST /api/support/tickets, /api/support/tickets/{id}/reply

── migration ──────────────────────────────────────────────
GET  /api/migrations                        list + active_migration_id
POST /api/migrations                        CREATE A RUN (409 if one is active)
GET  /api/migrations/{id}
GET  /api/migrations/{id}/items             failed/successful records
GET  /api/migrations/{id}/logs              merchant log (debug EXCLUDED)
GET  /api/migrations/{id}/download          plain-text report
POST /api/migrations/{id}/cancel
POST /api/migrations/{id}/retry-failed
```

**Admin — `middleware('admin.auth')`**
```
POST /api/admin/login                       throttled
GET  /api/admin/me, POST /api/admin/logout
GET  /api/admin/overview
POST /api/admin/queue/run | queue/retry-failed
GET  /api/admin/stores, /stores/{id}
POST /api/admin/stores/{id}/reset-entity    support tool: resync | forget
GET|POST|PATCH|DELETE /api/admin/plans...
GET  /api/admin/migrations
GET  /api/admin/migrations/{id}/debug       developer diagnostics (JSON)
GET  /api/admin/migrations/{id}/debug-log   developer log (streamed text)
GET  /api/admin/migrations/{id}
GET  /api/admin/errors
GET|POST|PATCH /api/admin/tickets...
GET|PUT|POST /api/admin/settings/mail | settings/branding
GET|POST|PATCH|DELETE /api/admin/content...
```

> Route order matters: `/migrations/{id}/debug` is registered **before**
> `/migrations/{id}`, or `debug` is matched as an id.

**Migration controllers**: `MigrationController` (merchant),
`Admin/AdminMigrationController` (admin + developer log), `WooCommerceController`
(connection + preview/counts).

---

## 5. Database

### Tables

| Table | PK | Key FKs | Purpose |
|---|---|---|---|
| `shops` | `id` | — | Install, encrypted `access_token`/`refresh_token`, scopes, **`api_bucket_size`, `api_restore_rate`, `limits_checked_at`** (measured Shopify budget), `shopify_plan` |
| `woocommerce_connections` | `id` | `shop_id` | `store_url`, encrypted `consumer_key`/`consumer_secret`, `status`, versions |
| `store_migrations` | `id` | `shop_id`, `woocommerce_connection_id` | One run. `status`, `selected_entities` (json), `options` (json), `planned_at`, `started_at`, `completed_at` |
| `migration_entities` | `id` | `migration_id` | Per-entity `total_items`, `processed_items`, `successful_items`, `failed_items`, `planned_items`, `last_source_id`, `status` |
| `migration_batches` | `id` | `migration_id` | One chunk. `entity_type`, `batch_number`, `page`, `per_page`, `source_ids` (json), `processed_offset`, `status`, `attempts`, `error` |
| `migration_items` | `id` | `migration_id`, `shop_id` | **Mapping / idempotency.** `entity_type`, `woocommerce_id`, `shopify_id`, `status`, `error`, `attempts`, `payload_hash` (unused) |
| `migration_logs` | `id` | `migration_id` | `level` (info/warning/error/**debug**), `entity_type`, `message` (TEXT), `context` (json) |
| `migration_files` | `id` | `shop_id` | Source-URL hash → Shopify CDN URL, for description-image re-hosting |
| `app_settings` | `id` | — | One **encrypted** JSON blob per key (`mail`, `branding`) |
| `admin_users`, `plans`, `subscriptions`, `support_tickets`, `ticket_replies`, `dashboard_contents` | | | Admin panel, billing, support |

> The runs table is **`store_migrations`**, not `migrations` — that name belongs to
> Laravel's own schema-migration table.

### Important indexes

| Index | Table | Why |
|---|---|---|
| `migration_items_tenant_source_unique` (`shop_id`,`entity_type`,`woocommerce_id`) | `migration_items` | **Duplicate prevention.** The database, not application logic, guarantees one mapping per source record per tenant |
| (`migration_id`,`entity_type`,`status`) | `migration_items` | Counter aggregation, failed-item lists |
| `migration_items_plan_metering_index` (`shop_id`,`entity_type`,`status`,`created_at`) | `migration_items` | Plan metering per billing period |
| `migration_items_pace_index` (`migration_id`,`entity_type`,`created_at`) | `migration_items` | Live ETA rolling window (polled every 3s) |
| `migration_logs_migration_id_id_index` | `migration_logs` | `order by id desc limit 100` on every poll |
| (`migration_id`,`status`) | `migration_batches` | `nextBatch()`, watchdog sweeps |

### Status fields

- `store_migrations.status` — `pending`, `processing`, `paused`, `completed`,
  `completed_with_errors`, `failed`, `cancelled`.
  Terminal set is `MigrationRunner::TERMINAL`.
- `migration_batches.status` — `pending`, `processing`, `completed`, `failed`.
- `migration_items.status` — `pending`, `success`, `failed`, `skipped`.

### How records are created / updated / deleted

- **Created**: `StoreMigration` + `MigrationEntity` rows by `MigrationController::store()`;
  `MigrationBatch` rows by `PlanBatchesJob`; `MigrationItem` rows by
  `AbstractMigrator::migrateItem()` (via `existingMapping()`).
- **Updated**: counters are **derived**, never incremented —
  `MigrationRunner::syncCounters()` recomputes them from `migration_items` by
  `GROUP BY status`. A replayed batch therefore cannot inflate progress.
- **Deleted**: `migration_batches` are deleted and re-planned on a re-run.
  `php artisan migration:prune` deletes logs of long-finished runs (`--days=90`) and
  `debug` lines sooner (`--debug-days=14`). GDPR `customers/redact` deletes matching
  mapping rows.

### Duplicate prevention (three layers)

1. **Unique index** on `migration_items` — the database refuses a second mapping.
2. **`AbstractMigrator::migrateItem()`** — looks up the mapping first and returns
   `skipped` when already successful (unless the migrator upserts).
3. **Interrupted-write recovery** — `attempts` is incremented **before** the Shopify
   call. On resume, `attempts > 0` with no `shopify_id` means "we don't know whether
   Shopify created it", so `findExisting()` asks Shopify (product by SKU then handle,
   customer by email) and **adopts** the existing object instead of creating a second.

### WooCommerce id → Shopify id

`migration_items.woocommerce_id` → `migration_items.shopify_id` (a `gid://shopify/...`
GID), scoped by `shop_id` + `entity_type`. This is the only mapping store; migrators
look records up here rather than searching Shopify.

---

## Migration System Deep Dive

### Lifecycle

**1 — Connect WooCommerce.** `POST /api/woocommerce/connect`. Credentials are
**tested before anything is persisted**; a failure returns 422 with
`kept_existing: true` and leaves the stored connection untouched (a typo used to
destroy a working connection). Stored encrypted.

**2 — Preview.** `GET /api/woocommerce/preview` counts every entity, applies plan
verdicts (`PlanAllowance`, `PlanPool`) and returns a modelled time estimate.
A core entity that cannot be counted while others succeed is reported in
`blocked_entities` (a warning); only when **nothing** core can be read is
`source_error` set. A run already in progress with real records migrated suppresses
`source_error` entirely — it disproves the claim.

**3 — Create.** `POST /api/migrations`:
- unknown entities dropped; `IMPLIED_ENTITIES` pulled in (skipping plan-blocked ones)
- **reviews without products → 422**
- WooCommerce must be connected → else 422
- another active run → **409**
- plan-locked entities → 422
- creates `store_migrations` + `migration_entities` rows, dispatches `StartMigrationJob`

**4 — `StartMigrationJob`** (`$timeout = 600`): counts each entity, applies plan caps
and the shared `PlanPool` in dependency order, writes warnings (metafields not in plan,
the order staff-notification notice), sets `planned_at = null`, deletes old batches,
dispatches `PlanBatchesJob`.

**5 — `PlanBatchesJob`**: walks each entity's ids and **pins** them to chunks
(`migration_batches.source_ids`). Resumable via `planned_items` / `last_source_id`,
time-bounded, hands chunks to the runner as it creates them so work starts within
seconds. Sets `planned_at` when done — **finalization is gated on it**.

**6 — `ProcessBatchJob`** (one per chunk, `$tries = 3`, `$timeout = 300`,
35s self-imposed budget):
```
claim(batch)                     conditional UPDATE — exactly one worker wins
fetchPage()                      cached; else fetch by pinned ids (include=)
slice to processed_offset        resume
prefetchPage()                   bulk lookups (image sizes)
foreach record:
    isHalted()?                  cancel/pause → hand chunk back as pending
    migrateItem()                ← AbstractMigrator template method
    processed_offset = absolute  checkpoint BEFORE recording the result
    syncCounters() every 1s
    time budget hit → continueInNewJob() (keeps the lease)
mark completed → syncCounters → refreshEntityStatus → advance()
```

**7 — `MigrationRunner::advance()`**: takes a 15s cache lock (prevents a dispatch
storm), finds the oldest outstanding batch, computes free slots as
`concurrencyFor(entity) − inFlight(entity)`, dispatches that many. When nothing is
outstanding **and** `planned_at` is set, dispatches `FinalizeMigrationJob`.

**8 — `FinalizeMigrationJob`** (`$timeout = 180`): re-derives every counter, reconciles
shortfalls, sets the final status, sends the completion email.

### Data transformation

Each migrator implements `App\Migration\Contracts\Migrator`:

```php
entityType(): string
count(WoocommerceConnection): int
fetchBatch(WoocommerceConnection, int $page, int $perPage): array
listSourceIds(WoocommerceConnection, int $page, int $perPage): ?array
fetchByIds(WoocommerceConnection, array $ids): array
migrateItem(StoreMigration, array $wooItem): MigrationItemResult
dependsOn(): array
sourceId(array $wooItem): int
```

`AbstractMigrator` implements everything except `doMigrate()`, which is the only method
a new migrator must write.

### Failure handling

| Failure | Handling |
|---|---|
| One record errors | Recorded as a failed `migration_item`; the run continues |
| Optional field rejected | `ShedsRejectedFields` drops it and retries (max 3 rounds), records a warning. Required fields still fail |
| Top-level Shopify error (`ACCESS_DENIED`, missing scope, PCD not approved) | `fatalShopifyError()` returns a merchant-readable sentence in one API call — no shedding loop |
| Throttled | Batch pauses and resumes (see §8) |
| Whole page unreadable | Batch fails; `RetryFailedItemsJob::reviveFailedBatches()` can revive it |
| Job killed / out of tries | `failed()` marks the batch failed and **still calls `advance()`** |
| Whole run stalled | `migration:watchdog` revives every stalled batch |

### Media / images

Product and variant images are **inline** in `ProductMigrator` (Shopify downloads them
itself; `productSet` blocks until it has). `ImageSizeResolver` picks the WordPress-generated
size the merchant chose. After 5 image rejections in one run the migrator stops sending
images entirely for that migration (cache flag `migration:{id}:media_unreachable`).

Images **inside descriptions** are opt-in (`rehost_description_images`):
`DescriptionImages` uploads each same-origin `<img>` via `fileCreate`, polls for a CDN
URL, rewrites the HTML, and caches source-URL → CDN-URL in `migration_files` so a banner
repeated across 20k products is uploaded once.

---

## 7. Per-entity migration behaviour

| Entity | Shopify call | Upserts? | Notes |
|---|---|---|---|
| `product` | `productSet` | yes | Variants/options/images/metafields inline. Metafields+brand tags gated by plan |
| `category` | `collectionCreate`/`Update` | yes | Products linked automatically |
| `customer` | `customerCreate` | no | Phone normalised to E.164 (`PhoneNumber`) |
| `order` | `orderCreate` | no | `sendReceipt: false`; line prices **pre-discount**; never sends `sku` |
| `coupon` | `discountCodeBasicCreate` | no | Unsupported types fail loudly |
| `blog` | `blogCreate`/`blogUpdate` | yes | From WP post categories |
| `article` | `articleCreate` | no | Needs `_embed=wp:featuredmedia` for the image |
| `review` | `metafieldsSet` per product | — | Source grain is a review, write grain is a product |

### Orders vs products — why they differ

|  | Products | Orders |
|---|---|---|
| Bounded by | Merchant's WooCommerce host + Shopify point budget | **Shopify's separate `orderCreate` limit** |
| Concurrency | `MIGRATION_SOURCE_CONCURRENCY` (8) | Per Shopify tier: 2 / 3 / 6 / 8 |
| WooCommerce reads | Heavy — variations + image metadata per record | Light — ~1 request per 100 records |
| Shopify cost | Slow per call (synchronous image download) | Fast per call, but rate-limited |
| Throttle signal | 429 / GraphQL `THROTTLED` (both carry timing) | A plain `userError` with **no timing at all** |

That last row is why order pacing is a guess and product pacing is not.

---

## 8. Rate limits, throttling and concurrency

### Three distinct Shopify limits

1. **GraphQL point budget** — documented. Every response carries
   `extensions.cost.throttleStatus`; `ShopifyPlan::remember()` stores the bucket and
   restore rate on the shop. `ShopifyClient::pace()` sleeps as the bucket drains
   (reserve 25%, max 5s) so we rarely get refused at all.
2. **HTTP 429 / GraphQL `THROTTLED`** — carries `Retry-After` or enough budget data to
   compute the wait exactly. **Honoured verbatim.**
3. **`orderCreate`'s own limit** — **undocumented by Shopify**, reported as an ordinary
   `userError` ("Too many attempts…") with no timing. Detected by
   `ShopifyClient::looksRateLimited()`, which scans **only `userErrors[].message`**
   (scanning the whole body meant a product titled "Throttled" parked a batch forever).

### Backoff

`App\Support\ThrottleBackoff` — shared by `ProcessBatchJob` and `RetryFailedItemsJob`.

- **Measured** wait → used exactly.
- **Guessed** wait → climbs `config('migration.soft_throttle_backoff')` = `5, 10, 20, 40, 60`s.
- Only **stalled** waits (no record migrated since the last one) escalate; progress resets it.
- The ramp is **added** to the give-up budget, never spent from it — otherwise a store
  with a genuine 60s window would be abandoned after ~2 minutes.

### Concurrency

```
effective = min( ShopifyPlan::concurrencyFor(shop) , ShopifyPlan::entityCeiling(shop, entity) )

concurrencyFor = ceil(restoreRate / write_points × write_seconds), capped by max_concurrency
entityCeiling  = config('migration.entity_concurrency')[entity][tier] ?? [default]
```

| Shopify plan | restore | tier | derived | products | orders |
|---|---|---|---|---|---|
| Basic | 100/s | standard | 6 | 6 | 2 |
| Grow | 100/s | standard | 6 | 6 | 2 |
| Advanced | 200/s | advanced | 12 | 8 | 3 |
| Plus | 1000/s | plus | 12 | 8 | 6 |
| Enterprise | 2000/s | enterprise | 12 | 8 | 8 |

`max_concurrency` (12) is deliberately **above** `entity_concurrency.default` (8): while
they were equal, raising an order tier ceiling did nothing because the global cap clipped
it first.

**Parallelism is within one entity, never across them** — products cannot start until
every category batch is done, because products reference the collections.

---

## 9. Queue and workers

- **Driver**: `database` (`QUEUE_CONNECTION`). **Queue name**: `default` — a single
  queue; nothing is split by entity or priority.
- **`retry_after` = 900s**. Every job timeout must stay below it.

| Job | `$tries` | `$timeout` | Why |
|---|---|---|---|
| `StartMigrationJob` | 3 | 600 | Counting is many slow HTTP calls |
| `PlanBatchesJob` | 3 | 300 | Time-bounded, resumable |
| `ProcessBatchJob` | 3 | 300 | Above one slow record, below `retry_after` |
| `RetryFailedItemsJob` | 3 | 300 | Same shape |
| `FinalizeMigrationJob` | — | 180 | SMTP hand-off can hang |

### Three ways workers exist — never combine them

| Mechanism | File | Use when |
|---|---|---|
| **supervisor** `numprocs=8` | `deploy/supervisor/store-shift-worker.conf` | VPS with root |
| **cron keeper** (`MIGRATION_WORKERS`, default 4) | `deploy/queue-workers.sh` | Shared hosting, no process manager |
| **`QueueKeeper`** self-starter | `app/Support/QueueKeeper.php` | Fallback; slot-leased pool bounded by `QUEUE_AUTO_WORKERS` |

Set **`QUEUE_AUTO_WORKER=false`** wherever supervisor or the cron keeper runs, or two
pools stack.

`QueueKeeper` spawns detached `queue:work --stop-when-empty --max-time=55 --timeout=300
--tries=3`, leasing slots through the cache (`queue-keeper:worker-slot:N`) for exactly
the worker's 55s life. `canSpawn()` reports honestly when `proc_open` is disabled, so the
admin panel shows cron instructions instead of a button that cannot work.

### Diagnosing workers

`MigrationDeveloperLog` records every job's `host:pid`, so
**Admin → Migrations → Developer log** answers "how many processes are actually working
this run?" — a question the database queue driver will not answer.

If fewer processes are busy than workers are running, the run is **ceilinged, not
starved** (see §8).

---

## 10. Scheduled tasks and cron

Declared in `routes/console.php`:

| Schedule | Command | Purpose |
|---|---|---|
| **every minute** | `queue:keepalive` | Keeps something draining the queue when nobody has the app open |
| **every 5 minutes** | `migration:watchdog` | Revives stalled batches/planners, re-queues failed jobs |

`migration:prune` is **not** in the scheduler — it is a separate cron line on the VPS
(`0 3 * * *`).

VPS crontab (from `DEPLOYMENT-VPS.md` §11), run as `www-data`:
```cron
*/5 * * * * cd /var/www/store-shift/app/backend && php artisan migration:watchdog >> /var/log/store-shift-watchdog.log 2>&1
0 3 * * *   cd /var/www/store-shift/app/backend && php artisan migration:prune    >> /var/log/store-shift-prune.log    2>&1
```

> **Do not add `queue:keepalive` to the VPS crontab.** With supervisor running it starts
> a second pool.

**UNKNOWN**: whether these crons and supervisor are actually installed on the live
server. The repository carries the configuration; the running state cannot be verified
from the code. `deploy/healthcheck.sh` answers it on the box.

---

## 11. Frontend

### Progress UI

`MigrationDetails.tsx` polls `GET /api/migrations/{id}` **every 3 seconds**
(`setInterval(load, 3000)`); `Dashboard.tsx` does the same for the active run. There are
**no websockets** — the database is the source of truth and the frontend holds no
migration state, so closing the tab changes nothing.

Each poll returns entity counters, `source_health`, `failed_batches` and `estimate`.

### ETA

`App\Support\MigrationEstimate`:

- **Pre-start** (`forPlan`) — modelled from record counts × per-entity weight ×
  `BASELINE_SECONDS_PER_UNIT`, spread over the entity's *own* allowed workers, with an
  orders-per-minute floor. `basis: "plan"`.
- **Live** (`forRun`) — measures **each entity separately** from its own
  `migration_items.created_at`, preferring a **rolling 15-minute window**. Entities not
  yet started are modelled × a calibration learned from those that have run.
  `basis: "progress"`.

Measuring per entity is essential: a run that does 10k products at 166/min then drops to
4 orders/min was previously priced by one cumulative average, so the order phase was
quoted at product speed.

`workers` reported to the UI is what the **current phase** may use, not the shop maximum.
Cached 15s (`SafeCache`) because the page polls every 3s.
`duration.ts` rounds coarsely on purpose — precision is a claim.

---

## 12. Authentication

### Merchant (Shopify embedded)

```
Install:  /api/auth → Shopify consent → /api/auth/callback (HMAC verified)
          → token exchange (expiring=1) → encrypted in shops
Per call: App Bridge idToken() → Authorization: Bearer <JWT>
          → AuthenticateSessionToken verifies HS256, aud/dest/exp/nbf (60s leeway)
          → also exchanges the session token for a fresh offline token
```

Local dev fallback: `X-Shop-Domain` header (rejected in production).

**Frontend identity** lives only in `src/shopContext.ts`. `capture()` runs in `main.tsx`
before React mounts and remembers `shop` (localStorage) and embedded-ness
(sessionStorage), because Shopify puts `host`/`shop` on the **first URL only** and
client-side navigation drops the query string.

### Super admin

`POST /api/admin/login` (rate limited) → random 64-char token; only its SHA-256 hash is
stored. Sent as `Authorization: Bearer`, checked by `AuthenticateAdmin`.

### WooCommerce

Basic auth with consumer key/secret over HTTPS, encrypted at rest, **tested before
persisting**. `GET /api/woocommerce/connection` reads the stored (masked) connection
with no network call.

---

## 13. Email

```
FinalizeMigrationJob → Mail::to($email)->send(new MigrationCompleted($migration))
```

Only when the merchant asked (`options.notify_email`). SMTP comes from `app_settings`
(encrypted, admin-editable) overriding `.env` at boot via `MailSettings::apply()`;
`MailSettings::canDeliver()` tells the wizard whether to even offer the checkbox.
A dead SMTP server must never undo a finished migration — the status is written first.

The branded header is `resources/views/vendor/mail/html/header.blade.php`, reading
`Branding::current()`. The logo URL is absolute and carries a cache-busting token
derived from the stored filename **and** the clock (Gmail/Outlook cache images by URL).

---

## 14. Static pages

Plain HTML + one stylesheet, no build step. `/developers/` is the merchant-facing
technical documentation (what migrates, field mapping, engine behaviour, plans, rate
limits, troubleshooting) and should be updated whenever behaviour visible to a merchant
changes.

---

## 15. Environment variables

**Names and purposes only — never commit or document actual values.**

| Variable | Purpose |
|---|---|
| `APP_NAME`, `APP_URL`, `APP_KEY` | Laravel basics. `APP_NAME` reaches the email `<title>` and `MAIL_FROM_NAME` |
| `DB_*` | Database connection |
| `QUEUE_CONNECTION` | Queue driver (`database`) |
| `DB_QUEUE_RETRY_AFTER` | Must exceed every job timeout |
| `QUEUE_AUTO_WORKER` | `false` where supervisor/cron runs the pool |
| `QUEUE_AUTO_WORKERS` | Size of the self-started pool |
| `SHOPIFY_API_KEY` / `SHOPIFY_API_SECRET` | **Secret** — app credentials |
| `SHOPIFY_APP_URL` | Public HTTPS base; also builds the email logo URL |
| `SHOPIFY_TOKEN_LEEWAY` | JWT clock-skew allowance |
| `SHOPIFY_BILLING_TEST` | Test-mode charges |
| `MIGRATION_PER_PAGE` | Records per WooCommerce page / chunk |
| `MIGRATION_MAX_CONCURRENCY` | Absolute batch ceiling per shop |
| `MIGRATION_SOURCE_CONCURRENCY` | Ceiling for WooCommerce-heavy entities |
| `MIGRATION_ORDER_CONCURRENCY_{STANDARD,ADVANCED,PLUS,ENTERPRISE}` | Order ceiling per Shopify tier |
| `MIGRATION_SHOPIFY_WRITE_POINTS` / `_SECONDS` | Inputs to the derived worker count |
| `MIGRATION_MAX_CACHED_PAGE_BYTES` | Chunk cache limit (must fit `max_allowed_packet`) |
| `MIGRATION_DEBUG_LOG` | Per-job developer diagnostics |
| `MIGRATION_IMAGE_SIZE`, `MIGRATION_WEIGHT_UNIT`, `MIGRATION_BARCODE_KEYS`, `MIGRATION_META_NAMESPACE`, `MIGRATION_SKIP_META_KEYS`, `MIGRATION_BRANDS_AS_TAGS`, `MIGRATION_DEFAULT_VENDOR` | Migration defaults |
| `MAIL_*` | SMTP fallback (DB settings win) |
| `PLAN_SEED_OVERWRITE` | Let `AdminSeeder` overwrite admin-edited plans |
| `ADMIN_PASSWORD` | **Secret** — initial admin seed |

Worker-script variables: `MIGRATION_WORKERS`, `WORKER_TIMEOUT`, `WORKER_MAX_TIME`,
`BACKEND_DIR`, `PHP_BIN` (`deploy/queue-workers.sh`).

---

## 16. Important files

| File | Purpose | Why it matters | Safe to modify? | Related |
|---|---|---|---|---|
| `app/Migration/AbstractMigrator.php` | Idempotency template method | **Every migrator inherits it.** Owns duplicate prevention and interrupted-write recovery | ⚠️ High risk — a mistake duplicates records for every entity | `MigrationItem`, all migrators |
| `app/Migration/MigrationRunner.php` | Dispatch, counters, logging | Decides how many batches run; derives all progress | ⚠️ High risk | `ProcessBatchJob`, `ShopifyPlan` |
| `app/Jobs/ProcessBatchJob.php` | Processes one chunk | Claims, leases, resumes, self-advances | ⚠️ High risk — read the docblock first | `MigrationRunner`, `ThrottleBackoff` |
| `app/Jobs/PlanBatchesJob.php` | Pins source ids to chunks | Gates finalization via `planned_at` | ⚠️ Medium | `MigrationBatch` |
| `app/Jobs/StartMigrationJob.php` | Counts + plan caps | Entry point of a run | ✅ Reasonable | `PlanPool`, `PlanAllowance` |
| `app/Jobs/FinalizeMigrationJob.php` | Final status + email | Reconciles shortfalls | ✅ Reasonable | `MigrationCompleted` |
| `app/Services/ShopifyClient.php` | GraphQL client | All throttle detection lives here | ⚠️ High risk | `ThrottleBackoff`, `ShopifyPlan` |
| `app/Services/WooCommerceClient.php` | REST client | Counting, probing, retry policy | ⚠️ Medium | `SourceHealth` |
| `app/Services/ShopifyPlan.php` | Tier + concurrency | **Single** plan-detection point | ⚠️ Medium | `config/migration.php` |
| `app/Support/ThrottleBackoff.php` | Backoff ladder | Shared; weakening it burns records | ⚠️ High risk | `ProcessBatchJob`, `RetryFailedItemsJob` |
| `app/Support/MigrationEstimate.php` | ETA | Two code paths must agree with the runner | ✅ Reasonable | `ShopifyPlan` |
| `app/Support/MigrationDiagnostics.php` | Per-job telemetry | Must never throw, never slow the run | ✅ Reasonable | `MigrationDeveloperLog` |
| `app/Support/QueueKeeper.php` | Self-started workers | Wrong here = no workers or too many | ⚠️ Medium | `KeepQueueAlive` |
| `config/migration.php` | All migration tuning | Every concurrency/throttle knob | ✅ Yes — read the comments | `ShopifyPlan`, `MigrationRunner` |
| `config/queue.php` | `retry_after` | Below every job timeout, or jobs double-run | ⚠️ High risk | all jobs |
| `app/frontend/src/shopContext.ts` | Tab identity | Wrong = install screen for logged-in merchants | ⚠️ Medium | `App.tsx`, `api/client.ts` |
| `app/index.php` / `backend/public/index.php` | Front controllers | **Never copy one over the other** | ❌ Leave alone | — |

---

## 17. Common change guide

| Change | Where | Watch out for |
|---|---|---|
| **Product migration** | `Migrators/ProductMigrator.php` → `doMigrate()` | Variants/images are inline; plan-gates metafields |
| **Variation migration** | Same file — variants are inline | `ProductVariationMigrator` is a no-op; do not revive it |
| **Image migration** | `ProductMigrator` + `ImageSizeResolver`; descriptions in `DescriptionImages` | Media short-circuit after 5 failures |
| **Category migration** | `CategoryMigrator.php` | Upserts — never fall through to a create on a refused update |
| **Customer migration** | `CustomerMigrator.php`, `Support/PhoneNumber.php` | Phone must be E.164 |
| **Order migration** | `OrderMigrator.php` | Never send `sku`; prices must be **pre-discount**; keep `sendReceipt: false` |
| **Review migration** | `ReviewMigrator.php` | Source grain = review, write grain = product. Requires products |
| **Migration progress** | `MigrationRunner::syncCounters()` | Counters are **derived** — never increment |
| **ETA** | `Support/MigrationEstimate.php` | Runner and estimate must agree — a test locks this |
| **Queue behaviour** | `config/queue.php`, `app/Jobs/*` | Job timeout < `retry_after` |
| **Worker concurrency** | `config/migration.php`, `ShopifyPlan` | `min()` of derived and ceiling; raise the global cap first |
| **WooCommerce API** | `Services/WooCommerceClient.php` | Never retry a 4xx; keep `SourceHealth` timing |
| **Shopify API** | `Services/ShopifyClient.php` | Keep `looksRateLimited()` scoped to `userErrors[].message` |
| **Migration UI** | `pages/NewMigration.tsx`, `MigrationDetails.tsx` | Mirror backend dependency rules |
| **Add a migration type** | `Migrators/NewX.php` + register in `MigratorRegistry` + wizard `ENTITY_OPTIONS` + plan key | Declare `dependsOn()`; add `wooSource()` for id pinning |
| **Migration validation** | `MigrationController::store()` | Frontend mirrors it — change both |
| **Email notifications** | `FinalizeMigrationJob`, `Mail/MigrationCompleted`, `views/vendor/mail/html/header.blade.php` | Status is written before sending |

### Adding a new migration entity — checklist

1. Implement `doMigrate()` on a class extending `AbstractMigrator`.
2. Add `wooSource()` so it gets id pinning and `fetchByIds` for free.
3. Declare `dependsOn()`.
4. Register in `MigratorRegistry::MIGRATORS`.
5. Add to `WooCommerceController::$display` and, if it is a `wc/v3` entity,
   `CORE_ENTITIES`.
6. Add a weight in `MigrationEstimate::WEIGHTS`.
7. Add a plan limit key + `Shop::limitFor()` mapping.
8. Add to the wizard's `ENTITY_OPTIONS`.
9. Document it in `/developers/` §4.
10. Tests: transform fixture, idempotency (double-run = one record + SKIPPED),
    failure path.
