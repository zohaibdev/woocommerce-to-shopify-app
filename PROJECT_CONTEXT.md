# PROJECT_CONTEXT.md — Shop Shift

> **Scope of this file.** High-level orientation: what the product is, how it is put
> together, and the rules a developer has to know before touching anything. For the
> full technical walk-through see **[ARCHITECTURE.md](ARCHITECTURE.md)**; for anything
> you have to *run*, see **[COMMANDS.md](COMMANDS.md)**.
>
> **Companion file.** [`PROJECT-CONTEXT.json`](PROJECT-CONTEXT.json) is the
> machine-readable snapshot of the same project (it is what `CLAUDE.md` points AI
> agents at, and it carries a long `known_gotchas` list worth reading). This file is
> the human-readable version. **When one changes, change both** — they are two views
> of one project, and a contradiction between them is worse than either being stale.
>
> Everything below was read out of the codebase. Anything that could not be verified
> from the repository is marked **UNKNOWN** rather than guessed.

---

## Project Overview

Shop Shift is an **embedded Shopify app** that migrates a merchant's **WooCommerce**
store into their **Shopify** store.

| | |
|---|---|
| Production URL | `https://shopshift.net` |
| Merchant app | `https://shopshift.net/app` (embedded in the Shopify admin) |
| Super-admin panel | `https://shopshift.net/app/admin` (own auth, not Shopify) |
| Marketing / legal site | `https://shopshift.net/` (static HTML, not React) |
| Repository | `https://github.com/zohaibdev/store-shift` |
| Status | Deployed; **not** published on the Shopify App Store |

> **Naming.** The product is **Shop Shift** (renamed from "Store Shift", itself
> renamed from the original "Shift Shop").
> The repository folder, the git remote and the supervisor config file are named
> `store-shift`. Those are *paths and identifiers* — do not rename them; renaming
> breaks deployment.

## What Shop Shift Does

Reads a merchant's WooCommerce store over the REST API and writes it into Shopify over
the Admin GraphQL API. Migrated entities (verified against `MigratorRegistry`):

| Entity | Becomes in Shopify | Notes |
|---|---|---|
| `product` | Product | Variants, options, images and metafields are **inline**, not separate jobs |
| `category` | Custom collection | Upserts |
| `customer` | Customer | No passwords (incompatible hashes) |
| `order` | Order | Historical, with tax/discounts/transactions |
| `coupon` | Discount code | percent / fixed_cart / fixed_product only |
| `blog` | Blog | From WordPress **post categories** |
| `article` | Article | From `wp/v2/posts` |
| `review` | Product metafield | Shopify has no native reviews — see Business Rules |

Registered but **no-ops**, kept only so old migrations do not break:
`attribute`, `variation`, `media`. Their `count()` returns 0.

## Technology Stack

| Layer | Verified from |
|---|---|
| Laravel 12, PHP 8.2 | `composer.json`, `bootstrap/app.php` |
| React 18 + Vite + TypeScript | `app/frontend/package.json` |
| Shopify Polaris + App Bridge | `App.tsx`, `resources/spa/index.html` |
| MySQL (production) / SQLite (local) | `config/database.php`, `.env*` |
| Queue: **database** driver | `config/queue.php` → `QUEUE_CONNECTION`, default `database` |
| Shopify Admin **GraphQL** API | `ShopifyClient`, pinned via `services.shopify.api_version` |
| WooCommerce **REST v3** + `wp/v2` | `WooCommerceClient::baseFor()` |

Redis + Horizon are **not** in use. The queue is the database driver; `config/queue.php`
contains a Redis block but nothing selects it by default.

## Application Architecture

Two separate surfaces served by one Laravel app:

- **Merchant app** — embedded in the Shopify admin at `/app/`. Auth = Shopify App
  Bridge session token (JWT).
- **Super admin** — a separate React section at `/app/admin`. Auth = its own bearer
  token. No Shopify involvement.

There is **no Repositories layer and no Events/Listeners** — verified: `app/Repositories`,
`app/Events` and `app/Listeners` do not exist. Business logic lives in
`app/Migration/`, `app/Services/` and `app/Support/`.

### Entry points

| Entry point | Used by |
|---|---|
| `app/index.php` | **Production** front controller — paths point at `./backend/` |
| `app/backend/public/index.php` | **Local dev** front controller — paths use `../` |
| `app/backend/resources/spa/index.html` | Built SPA shell, served by `routes/web.php` |
| `app/frontend/src/main.tsx` | Frontend bootstrap |

> **Do not copy one `index.php` over the other.** They differ only in relative paths, and
> swapping them produces a 500 on every route.

## Frontend

```
app/frontend/src/
├── main.tsx              bootstrap; calls shopContext.capture() BEFORE React mounts
├── App.tsx               routing + merchant/admin surface switch
├── shopContext.ts        who this tab is (shop domain, embedded?) — single source
├── api/client.ts         merchant API client + all response types
├── admin/                super-admin SPA (own api.ts, own pages)
├── pages/                merchant screens
├── components/           shared components (currently: SecurityPluginWarning)
├── duration.ts           human-readable durations for the ETA
├── settings.ts           merchant UI preferences (localStorage)
└── navigation.ts         redirectTop() — App Bridge-safe top-level navigation
```

There is **no `hooks/` directory**; state is local to pages.

## Backend

```
app/backend/app/
├── Migration/            THE CORE — see ARCHITECTURE.md
│   ├── Contracts/Migrator.php
│   ├── AbstractMigrator.php        idempotency template method
│   ├── MigrationRunner.php         dispatch/advance/counters/logging
│   ├── MigratorRegistry.php        entity → class, dependency ordering
│   ├── Migrators/                  11 migrators
│   └── Concerns/ShedsRejectedFields.php
├── Jobs/                 5 jobs (see Migration System below)
├── Services/             Shopify + WooCommerce clients, OAuth, billing, mail
├── Support/              13 helpers: concurrency, estimates, plans, diagnostics
├── Http/Controllers/     merchant + Admin/ + Auth/
├── Http/Middleware/      3: session token, admin auth, KeepQueueAlive
├── Console/Commands/     4: watchdog, prune, keepalive, sync-webhooks
├── Models/               16 Eloquent models
└── Mail/                 4 mailables
```

## Database

Migration-critical tables (full detail in ARCHITECTURE.md):

| Table | Holds |
|---|---|
| `shops` | Shopify install, encrypted tokens, **measured API limits** |
| `woocommerce_connections` | Store URL + encrypted key/secret |
| `store_migrations` | One run. **Named `store_migrations`, not `migrations`** — that is Laravel's own table |
| `migration_entities` | Per-entity counters and planning progress |
| `migration_batches` | One row per chunk; pinned source ids, resume offset |
| `migration_items` | **The mapping/idempotency table** — unique per shop+entity+source id |
| `migration_logs` | info / warning / error / **debug** per run |
| `migration_files` | Source-URL hash → Shopify CDN URL (description images) |

## Shopify Integration

- `ShopifyClient` — GraphQL client. Handles 429, GraphQL `THROTTLED`, and the
  soft rate-limit that `orderCreate` reports as a plain `userError`. Paces itself
  against the shop's remaining point budget.
- `ShopifyOAuth` — install flow, HMAC verification, scope diffing.
- `ShopifyTokenService` — **expiring** offline tokens (`expiring=1`), refresh flow.
- `ShopifyPlan` — reads the shop's real API restore rate from every response and
  derives how many batches may run at once. Also owns **tier detection** and the
  per-entity concurrency ceilings.
- `ShopifyBilling` — app subscriptions.
- `ShopifyWebhookRegistrar` — reconciles webhook subscriptions (updates, never duplicates).
- `SessionToken` — verifies the App Bridge JWT.

Scopes (from config): `write_products, write_customers, read_products, read_customers,
write_inventory, read_orders, write_orders, write_content, write_discounts`.
**Adding a scope forces every installed merchant to re-authorize.**

## WooCommerce Integration

`WooCommerceClient` wraps REST v3 (`wc/v3`) and plain WordPress (`wp/v2`) behind one
timed, retrying, paginating client.

- Auth is Basic (consumer key/secret) and authenticates **`wc/v3` only** — `wp/v2` is
  read unauthenticated, so blogs/articles only ever see *published* content.
- Counts come from `X-WP-Total`; when a host strips that header the client **probes**
  for the last page instead of returning 0 (returning 0 read as "empty store").
- Retries only transient failures (connection, 5xx, 429) — **never** a 4xx.
- Every request is timed and reported to `SourceHealth` (merchant-facing) and
  `MigrationDiagnostics` (developer log).

## Migration System

Job graph — **not** a Bus chain (a chain died at the first failed batch and froze the run):

```
StartMigrationJob        count entities, apply plan caps, create entity rows
        ↓
PlanBatchesJob           walk each entity's ids, PIN them to chunks (resumable)
        ↓
ProcessBatchJob × N      one chunk each; SELF-ADVANCING via MigrationRunner::advance()
        ↓
FinalizeMigrationJob     roll up status, reconcile shortfalls, send the email

RetryFailedItemsJob      separate sweep for "Retry failed items"
```

Five properties keep a run un-stallable (all in `ProcessBatchJob`'s docblock):
**exclusive** (conditional-UPDATE claim), **leased** (fencing token on handover),
**resumable** (absolute `processed_offset`), **time-bounded** (35s budget, then hand
to a fresh job), **self-advancing**.

## Queue and Workers

- Driver: **database**. Queue name: **`default`** (single queue — nothing splits by entity).
- `retry_after` = **900s**. Every job's `$timeout` must stay below it or the queue hands a
  running job to a second worker and every record on the page runs twice.
- Three ways workers exist, and they **must not be combined**:
  1. **supervisor** (`deploy/supervisor/store-shift-worker.conf`, `numprocs=8`) — VPS.
  2. **`deploy/queue-workers.sh`** on a one-minute cron — shared hosting.
  3. **`QueueKeeper`** — the app starts its own detached workers off ordinary API traffic.
     Set `QUEUE_AUTO_WORKER=false` when 1 or 2 is running, or you get two pools.
- Scheduler (`routes/console.php`): `queue:keepalive` **every minute**,
  `migration:watchdog` **every five minutes**.

## Authentication

| Surface | Mechanism |
|---|---|
| Merchant | App Bridge session token (JWT) → `shopify.session` middleware |
| Merchant (local dev only) | `X-Shop-Domain` header fallback |
| Super admin | `POST /api/admin/login` → random 64-char token, only the SHA-256 hash stored → `admin.auth` |

Frontend shop identity is resolved **once** in `src/shopContext.ts` and captured in
`main.tsx` before React mounts. Client-side navigation drops the query string, so
`?host` / `?shop` are read on first load and remembered.

## Billing

Implemented. Shopify app subscriptions via `ShopifyBilling`. Plans live in the **`plans`
table** and are editable from `/app/admin/plans` — no deploy needed. `AdminSeeder` only
*creates* missing plans (it used to overwrite, which reset admin-set prices on every
deploy); use `PLAN_SEED_OVERWRITE=true` to deliberately push code values out.

## Email System

- SMTP is configured **in the database** (`app_settings`, encrypted), edited at
  `/app/admin/settings`, applied at boot by `MailSettings::apply()`. `.env` still works
  as a fallback.
- Branding (logo + name) is `app_settings` too; the logo is streamed by the public
  `GET /api/branding/logo` route so no `storage:link` is needed.
- The mail header is overridden at `resources/views/vendor/mail/html/header.blade.php`
  so every email carries the logo.
- Mailables: `MigrationCompleted`, `ContactMessage`, `SupportTicketOpened`,
  `SupportTicketReplied`.

## Static Pages

Plain HTML at the repo root, sharing one stylesheet (`site.css`) and one SVG mark
(`logo.svg`, also `favicon.svg`): `index.html`, `privacy/`, `terms/`, `refund/`,
`contact/`, `developers/`. Brand colour is the single token `--accent: #025ec3`.

`index.html` contains an **embed guard**: opened with `host`/`embedded` params it
redirects to `/app/`, so the marketing page can never render inside the Shopify iframe.

## Important Business Rules

1. **Migrations never run in an HTTP request.** Controllers enqueue and report status.
2. **Everything is chunked** — WooCommerce paginated, Shopify batched.
3. **Every migrator is idempotent** via `migration_items` (unique `shop_id + entity_type
   + woocommerce_id`). Look up before create.
4. **Database is the source of truth.** The frontend polls; closing the browser must not
   affect a run.
5. **All credentials encrypted at rest**, never returned to the client (masked).
6. **Multi-tenant** — every query scoped by `shop_id`.
7. **One migration per shop at a time.** `POST /api/migrations` answers **409** otherwise —
   two concurrent runs corrupt both progress bars by claiming each other's mapping rows.
8. **Plan allowances reset per billing period**, anchored to the merchant's own billing
   date (`BillingPeriod`), not the 1st of the month.
9. **Order confirmation emails are suppressed** (`sendReceipt: false`), but Shopify's
   **staff "New order" notification cannot be disabled by any app** — merchants are
   warned before starting.
10. **Never send `sku` on order line items** — Shopify treats it as a product reference
    and auto-creates "Import placeholder" draft products.

## Migration Dependencies

Declared in `MigrationController::IMPLIED_ENTITIES` and mirrored in the wizard:

| Selecting | Also selects | Why |
|---|---|---|
| `review` | `product` | A review is a metafield **on** a product |
| `article` | `blog` | An article needs a blog to live in |
| `blog` | `article` | A blog with no posts is an empty container |
| `order` | `customer` | Orders associate to migrated customers by email |
| `variation` | `attribute` | Legacy; both are no-ops now |

**Products → Reviews is enforced on both ends.** If the plan blocks products the
implication is skipped (so the merchant is never refused over an entity they did not
choose) — and `POST /api/migrations` then returns **422** rather than starting a run that
would record every review as "the product was not migrated".

Ordering is topological via `MigratorRegistry::order()`. Parallelism happens **within**
one entity, never across them.

## Performance Considerations

- **Products and orders behave completely differently.** Products are bounded by the
  merchant's WooCommerce host; orders by a Shopify limit. Observed on one real store:
  ~10,000 products in ~1h vs ~2,500 orders in ~9h.
- **Concurrency is derived, not fixed** — from the shop's measured API restore rate
  (`ShopifyPlan::concurrencyFor`), then capped by the per-entity ceiling.

| Config | Meaning |
|---|---|
| `MIGRATION_MAX_CONCURRENCY` (12) | Absolute ceiling per shop |
| `MIGRATION_SOURCE_CONCURRENCY` (8) | Entities that read WooCommerce heavily |
| `MIGRATION_ORDER_CONCURRENCY_STANDARD/ADVANCED/PLUS/ENTERPRISE` (2/3/6/8) | Orders, by Shopify tier |

- **Order concurrency is per Shopify tier** — Basic and Grow share `standard` because
  Shopify gives them the same 100 pts/s budget.
- **Image size is the merchant's biggest lever.** `productSet` runs synchronously while
  Shopify downloads every image.
- **Page caching matters.** A chunk is cached between the jobs that share it; a page
  too big for the cache store is re-downloaded on every resume.
- **Throttle backoff ladder**: `5, 10, 20, 40, 60`s, escalating only on stalled waits.

## Known Limitations

Verified from code and comments:

- **Shopify does not document the `orderCreate` rate limit.** The per-tier ceilings are
  a starting point that the backoff settles; they are not a published contract.
- **Order line items are custom lines**, not linked to migrated product variants.
- **`payload_hash` on `migration_items` is unused** — no incremental/delta sync.
- **No rollback/undo**, despite the mapping table holding every Shopify id.
- **No SEO redirect generation** from Woo URLs to Shopify.
- **Planning is not a snapshot** — a record deleted *during* the id-listing pass can slip
  through. `FinalizeMigrationJob::reconcile()` detects and reports it rather than
  preventing it.
- **`wp/v2` content is published-only** (the consumer key does not authenticate it).
- **Reviewer email is deliberately dropped** (personal data in a storefront-readable
  metafield).
- **Local dev SQLite in this checkout is corrupt** (`database disk image is malformed`).
  Tests are unaffected — they use their own database.

## Development Workflow

```bash
cd app/backend  && composer install && php artisan migrate
cd app/frontend && npm install && npm run dev
```

Full command reference: **[COMMANDS.md](COMMANDS.md)**.

- Tests: `cd app/backend && php artisan test` (**382 passing** at time of writing)
- Typecheck: `cd app/frontend && npx tsc -b`
- Production build: `build-prod.bat` (frontend build + SPA shell + assets + zip)

**Restart queue workers after any backend change** — PHP holds the old classes in memory
for the life of the process.

## Where To Start For Common Changes

| I want to change… | Start here |
|---|---|
| What a product/order/customer sends to Shopify | `app/Migration/Migrators/{X}Migrator.php` |
| Idempotency, adoption of existing records | `app/Migration/AbstractMigrator.php` |
| How many batches run at once | `ShopifyPlan::concurrencyFor()` + `config/migration.php` |
| Throttle/backoff behaviour | `app/Support/ThrottleBackoff.php`, `ShopifyClient` |
| Estimated time remaining | `app/Support/MigrationEstimate.php` |
| Batch dispatch / progress counters | `app/Migration/MigrationRunner.php` |
| Chunk processing, resume, claims | `app/Jobs/ProcessBatchJob.php` |
| Adding a new migration entity | `MigratorRegistry` + a new `Migrator` implementation |
| Wizard UI, entity selection, warnings | `app/frontend/src/pages/NewMigration.tsx` |
| Why a run was slow | Admin → Migrations → **Developer log** (`MigrationDeveloperLog`) |

**Before changing migration behaviour, read
[ARCHITECTURE.md § Migration System Deep Dive](ARCHITECTURE.md#migration-system-deep-dive).**
The engine has a lot of non-obvious invariants (leases, fencing tokens, derived counters)
that look like redundancy and are not.
