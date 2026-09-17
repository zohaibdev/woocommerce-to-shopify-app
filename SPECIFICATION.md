# Store Shift — WooCommerce → Shopify Migration App — Master Specification

> **Status:** v1.0 — Architecture-first master spec.
> **Purpose:** Single source of truth for building a production-grade WooCommerce → Shopify data migration app, delivered to Shopify's App Store as an embedded app.
> **Golden rule:** Migrations NEVER run inside an HTTP request. They run as chunked, idempotent, retryable, rate-limit-aware queue jobs.

---

## 1. Product Overview

An embedded Shopify app that migrates a merchant's store data from a WooCommerce store into their Shopify store. The merchant installs the app on Shopify, connects their WooCommerce store via REST API credentials, selects what to migrate, and runs a background migration with live progress, history, error reporting, and retry.

### 1.1 Tech Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 11 (PHP 8.2+) |
| Database | MySQL 8 |
| Queue / cache | Redis + Laravel Horizon |
| Frontend | React 18 + Vite + TypeScript |
| UI kit | Shopify Polaris + App Bridge |
| Shopify API | Admin GraphQL API (2024-10 or latest stable) |
| WooCommerce API | WooCommerce REST API v3 |
| Auth | Shopify OAuth (embedded, session tokens) |

### 1.2 MVP Scope (Phase 1)

Migration entities included at launch:

- ✅ **Products** (title, body, SKU, price, compare-at price, inventory, status, tags, vendor, type)
- ✅ **Categories** → Shopify **Collections** (custom/manual collections)
- ✅ **Attributes** → Shopify product **Options**
- ✅ **Product Variations** → Shopify **Variants**
- ✅ **Images / Media** (product + variant images)
- ✅ **Customers** (profile + addresses; no password migration)

**Dependency rule:** selecting *Variations* requires *Attributes* (variants depend on options). The UI enforces this automatically.

### 1.3 Deferred (Phase 2+)

Orders, Coupons/Discounts, Reviews, SEO/meta, redirects, pages/blogs, incremental sync, scheduled migration, billing/plans, email notifications, multi-source WooCommerce.

---

## 2. Core Architecture Principles

These are non-negotiable and must be enforced in every implementation phase.

1. **Async by default.** All migration work runs via Redis queues managed by Horizon. Controllers only *enqueue* and *report status*.
2. **Chunked.** Large datasets are split into batches (default 100–250 items/batch). One batch = one job.
3. **Idempotent.** Re-running a job or migration must never create duplicates. Every migrated entity is recorded in a mapping table; jobs skip already-mapped items.
4. **Resilient.** Jobs implement retry with exponential backoff. Shopify `429` responses respect `Retry-After` (GraphQL: read `throttleStatus`).
5. **Database is source of truth.** Frontend holds no migration state; it polls the API. Browser close / refresh / logout never affects a running migration.
6. **Modular migration engine.** Each entity has its own migrator implementing a shared interface, so new entities plug in without touching the core.
7. **Secure by default.** All third-party credentials (WooCommerce keys, Shopify tokens) are encrypted at rest and never returned to the client in plaintext.
8. **Multi-tenant.** Every record is scoped to a `shop`. No cross-tenant data access is possible.

---

## 3. End-to-End User Flow

```
Install app (Shopify App Store)
   → Shopify OAuth  →  store shop + encrypted access token
   → Dashboard (empty state)
   → Connect WooCommerce (URL + consumer key + secret)
   → Test Connection  →  fetch Woo/WordPress version, counts
   → Migration Wizard
        Step 1: New migration
        Step 2: Select data (Products / Categories / Attributes / Variations / Media / Customers)
        Step 3: Preview & summary (available counts, estimated items)
        Step 4: Start  →  create Migration + batch jobs (queued)
        Step 5: Running  →  live per-entity progress (polling)
        Step 6: Complete / Completed-with-errors  →  report
   → Migration History  →  Migration Details  →  Failed items  →  Retry failed
```

---

## 4. Data Model (MySQL)

All tables use `bigIntIncrements` PKs, `timestamps`, and soft deletes where noted. Foreign keys are enforced. All migration data is scoped by `shop_id`.

### 4.1 `shops`
Represents an installed Shopify store (the tenant).

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| shopify_domain | string, unique | e.g. `my-store.myshopify.com` |
| access_token | text (encrypted) | Shopify Admin API token |
| scopes | string | granted scopes |
| name | string, nullable | shop display name |
| email | string, nullable | |
| plan_id | bigint, nullable FK | future billing |
| installed_at | timestamp | |
| uninstalled_at | timestamp, nullable | set on `app/uninstalled` webhook |
| timestamps | | |

### 4.2 `woocommerce_connections`
One active WooCommerce source per shop (design allows many for future multi-source).

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| shop_id | bigint FK → shops | |
| store_url | string | normalized https base URL |
| consumer_key | text (encrypted) | |
| consumer_secret | text (encrypted) | |
| status | enum(`connected`,`error`,`disconnected`) | |
| wc_version | string, nullable | captured on test |
| wp_version | string, nullable | captured on test |
| last_tested_at | timestamp, nullable | |
| timestamps | | |

### 4.3 `migrations`
One migration run.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| shop_id | bigint FK | |
| woocommerce_connection_id | bigint FK | |
| status | enum(`pending`,`processing`,`completed`,`completed_with_errors`,`failed`,`cancelled`,`paused`) | |
| selected_entities | json | e.g. `["products","categories","customers"]` |
| started_at / completed_at | timestamp, nullable | |
| timestamps | | |

Per-entity counters live in `migration_entities` (normalized), not as wide columns — this scales as entities are added.

### 4.4 `migration_entities`
Per-entity progress within a migration.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| migration_id | bigint FK | |
| entity_type | enum(`product`,`category`,`attribute`,`variation`,`media`,`customer`,…) | |
| status | enum(same set as migration) | |
| total_items | int, default 0 | |
| processed_items | int, default 0 | |
| successful_items | int, default 0 | |
| failed_items | int, default 0 | |
| timestamps | | |

`percentage` is computed (`processed/total`), not stored.

### 4.5 `migration_batches`
Tracks each chunk job for progress and resumability.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| migration_id | bigint FK | |
| entity_type | string | |
| batch_number | int | |
| page | int | Woo API page |
| per_page | int | |
| status | enum(`pending`,`processing`,`completed`,`failed`) | |
| attempts | int, default 0 | |
| error | text, nullable | |
| timestamps | | |

### 4.6 `migration_items` (the mapping / idempotency table)
The heart of idempotency & duplicate prevention.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| migration_id | bigint FK | |
| shop_id | bigint FK | denormalized for fast lookup |
| entity_type | string | |
| woocommerce_id | bigint | source id |
| shopify_id | string, nullable | e.g. `gid://shopify/Product/456` |
| status | enum(`pending`,`success`,`failed`,`skipped`) | |
| error | text, nullable | |
| payload_hash | string, nullable | detect content changes (incremental) |
| timestamps | | |

**Unique index:** `(shop_id, entity_type, woocommerce_id)` — guarantees one mapping per source object per tenant. Migrators look up this row before creating anything.

### 4.7 `migration_logs`

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| migration_id | bigint FK | |
| level | enum(`info`,`warning`,`error`) | |
| entity_type | string, nullable | |
| message | string | |
| context | json, nullable | |
| created_at | timestamp | |

### 4.8 Future tables (design now, build later)
`plans`, `subscriptions`, `usage_counters`, `notification_settings`, `scheduled_migrations`. Do **not** hardcode plan limits in migration logic — read from `plans`.

### 4.9 Entity Relationship Summary

```
shops 1───* woocommerce_connections
shops 1───* migrations
migrations 1───* migration_entities
migrations 1───* migration_batches
migrations 1───* migration_items   (unique: shop_id+entity_type+woocommerce_id)
migrations 1───* migration_logs
```

---

## 5. Migration Engine

### 5.1 Interface

```php
interface Migrator
{
    public function entityType(): string;          // 'product', 'category', ...
    public function count(WooConnection $c): int;    // total available in Woo
    public function fetchBatch(WooConnection $c, int $page, int $perPage): array;
    public function migrateItem(Migration $m, array $wooItem): MigrationItemResult;
    public function dependsOn(): array;              // e.g. ProductVariationMigrator -> ['attribute','product']
}
```

Implementations: `ProductMigrator`, `CategoryMigrator`, `AttributeMigrator`, `ProductVariationMigrator`, `MediaMigrator`, `CustomerMigrator`. Future: `OrderMigrator`, `CouponMigrator`, `ReviewMigrator`.

### 5.2 Orchestration & job graph

```
StartMigrationJob (per migration)
  └── for each selected entity, in dependency order:
        PlanEntityJob(entity)                 // calls count(), writes total, creates batch rows
          └── ProcessBatchJob(entity, page)   // one per chunk
                └── migrateItem() per row     // idempotent, logs result, updates counters
```

- **Dependency order:** categories & attributes → products → variations → media. Use Laravel job chaining / batches (`Bus::batch()`), with `then/catch/finally` to roll up final status.
- **Counters** are updated atomically (`increment()`), never by reading-then-writing in PHP.
- On batch completion, recompute the parent `migration_entities` and `migrations` status.

### 5.3 Idempotency algorithm (every `migrateItem`)

```
1. row = migration_items.firstOrCreate(shop_id, entity_type, woocommerce_id)
2. if row.status == success and row.shopify_id present → return SKIPPED
3. transform woo item → shopify input
4. call Shopify GraphQL create/update
5. on success: row.update(shopify_id, status=success); increment successful
6. on failure: row.update(status=failed, error=...); increment failed; log error
```

### 5.4 Rate limiting & retries

- Job `$tries = 5`, `backoff = [10, 30, 60, 120, 300]` seconds.
- Wrap Shopify calls: on GraphQL `THROTTLED` / HTTP 429, read `extensions.cost.throttleStatus` (or `Retry-After`) and `release()` the job with the required delay.
- Use a Redis-based rate limiter (`Redis::throttle`) to cap concurrent Shopify calls per shop.
- WooCommerce: paginate with `page`/`per_page` (default 100). Never fetch all at once. Handle Woo 5xx with retry.

### 5.5 Cancellation / Pause

- `POST /migrations/{id}/cancel` sets status `cancelled`. Each queued `ProcessBatchJob` checks migration status at start and self-terminates if `cancelled`/`paused`.
- Pause/resume is Phase 2 but the status enum and job guard exist from day one.

### 5.6 Failed items & retry

- Failed rows live in `migration_items` with `status=failed` + `error`.
- `POST /migrations/{id}/retry-failed` re-enqueues batch jobs restricted to failed `woocommerce_id`s. Idempotency ensures successes aren't touched.

---

## 6. Entity Mapping Reference

### 6.1 Product

| WooCommerce | Shopify (GraphQL `productCreate`) |
|---|---|
| name | title |
| description | descriptionHtml |
| sku | variant.sku |
| regular_price | variant.compareAtPrice or price |
| sale_price | variant.price |
| stock_quantity / manage_stock | inventoryQuantities / inventoryPolicy |
| status (publish/draft) | status (ACTIVE/DRAFT) |
| tags | tags |
| categories | collection membership (via CategoryMigrator mapping) |
| type/brand (attr/meta) | productType / vendor |

### 6.2 Categories → Collections
One-to-one: Woo category → Shopify **custom (manual) collection**. Store `woocommerce_id → shopify_collection_id` in `migration_items`. Products are added to collections after both exist (resolve via mapping).

### 6.3 Attributes → Options & Variations → Variants
- Woo global/local attributes → product `options` (name + values), e.g. Color[Red,Blue], Size[S,M].
- Woo variations → Shopify variants keyed by option combination (Red/S, Blue/M). Map SKU, price, stock, variant image.
- Enforce dependency: variations require attributes and the parent product to exist first.

### 6.4 Images / Media
- Handled as **separate jobs** after product creation.
- Fetch Woo image URL → create Shopify media (`productCreateMedia` via URL, or staged upload if needed).
- **Image failure never fails the product** — the image becomes its own failed `migration_item` (entity_type=`media`).

### 6.5 Customers
- Map: first/last name, email, phone, addresses (address1/2, city, province, country, zip).
- **No password migration** (hashes incompatible). Optionally send Shopify account invites (Phase 2).
- De-dupe by email.

---

## 7. API Contracts (Laravel → React)

All endpoints are authenticated via Shopify session token (App Bridge). All are scoped to the current shop. JSON.

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/shop` | current shop + connection status |
| POST | `/api/woocommerce/connect` | save & test Woo credentials |
| POST | `/api/woocommerce/test` | re-test connection; returns versions + counts |
| GET | `/api/woocommerce/preview` | available counts per entity |
| GET | `/api/migrations` | history (paginated) |
| POST | `/api/migrations` | create migration `{ entities: [...] }` → enqueues |
| GET | `/api/migrations/{id}` | status + per-entity progress (polled) |
| GET | `/api/migrations/{id}/items?entity=&status=failed` | items / failed items |
| GET | `/api/migrations/{id}/logs` | logs |
| POST | `/api/migrations/{id}/cancel` | cancel |
| POST | `/api/migrations/{id}/retry-failed` | retry failed items |

**Status response shape** (`GET /api/migrations/{id}`):

```json
{
  "id": 102,
  "status": "processing",
  "entities": [
    { "type": "product",  "total": 1250, "processed": 840, "successful": 820, "failed": 20, "percentage": 67.2 },
    { "type": "category", "total": 35,   "processed": 35,  "successful": 35,  "failed": 0,  "percentage": 100 }
  ],
  "started_at": "2026-08-08T10:30:00Z",
  "completed_at": null
}
```

**Security of credentials in responses:** `GET /api/woocommerce/*` returns masked keys only (`****…1234`), never plaintext secrets.

---

## 8. Shopify OAuth & Webhooks

- Embedded app using Shopify OAuth. Store `shop`, encrypted `access_token`, `scopes`.
- Scopes (minimum): `write_products, write_customers, read_products, read_customers` (+ `write_inventory`, `write_media` as needed).
- Verify HMAC on OAuth callback and all webhooks.
- Mandatory webhooks: `app/uninstalled`, plus GDPR webhooks `customers/data_request`, `customers/redact`, `shop/redact`.
- Session tokens (JWT) authenticate API calls from the embedded frontend.

---

## 9. Security Requirements

- Encrypt at rest: Woo consumer key/secret, Shopify access token (Laravel encrypted casts / `Crypt`).
- Never return secrets to the client; mask on read.
- Verify all Shopify HMACs (OAuth + webhooks).
- Enforce tenant scoping on every query (global scope on `shop_id`, or explicit `where`).
- Rate-limit public/auth endpoints. Validate & sanitize the Woo store URL (must be https, valid host).
- Store minimum PII; honor GDPR redaction webhooks.

---

## 10. Frontend (React + Polaris)

App navigation:

```
App
├── Home (Dashboard)
├── Migrations
│   ├── History (ResourceList / IndexTable)
│   ├── New Migration (Wizard)
│   └── Details (progress, items, logs, retry)
├── WooCommerce (Connection)
└── Settings (future: email, plan)
```

- **Dashboard:** connection status, entity counts, latest migration progress, primary CTA "Start New Migration".
- **Wizard:** Select data → Preview/summary → Running (per-entity `ProgressBar`, polling every 2–5s) → Complete report.
- **Polaris components:** Page, Card, Button, Banner, Badge, ProgressBar, DataTable/IndexTable, Modal, EmptyState, Toast, Spinner, Tabs, ResourceList.
- **State:** server is source of truth; poll `GET /api/migrations/{id}`. No optimistic migration state. SSE/WebSockets deferred.

---

## 11. Development Phases

| Phase | Deliverable |
|---|---|
| 1 | Architecture, DB migrations, models, base repositories |
| 2 | Shopify OAuth + token storage + webhooks (incl. GDPR) |
| 3 | WooCommerce connection + Test Connection + Preview |
| 4 | Migration engine core (interface, orchestrator, batching, idempotency, rate limiting) |
| 5 | Products migrator |
| 6 | Categories → Collections migrator |
| 7 | Attributes + Variations migrators (with dependency enforcement) |
| 8 | Media migrator |
| 9 | Customers migrator |
| 10 | Queue + Horizon + progress tracking + polling API |
| 11 | Migration history + details + failed items + retry + cancel + logs |
| 12 | Polaris UI polish |
| 13 | Testing (unit + feature + queue) |
| 14 | Production deployment (Horizon supervisor, env, monitoring) |

Do not start a phase's implementation before its predecessors' contracts are stable. Do not merge migration logic into controllers.

---

## 12. Testing Requirements

- **Unit:** each migrator's transform (Woo payload → Shopify input) with fixtures.
- **Idempotency:** running the same batch twice creates one Shopify record; second run marks SKIPPED.
- **Failure handling:** simulated Shopify 429 → job releases with backoff; simulated item error → recorded as failed, migration continues.
- **Feature:** API endpoints (create migration enqueues jobs; status reflects DB; retry-failed re-enqueues only failed).
- **Fakes:** mock WooCommerce & Shopify HTTP with `Http::fake()`. `Queue::fake()` for enqueue assertions.

---

## 13. Definition of Done (MVP)

- [ ] Merchant installs via Shopify OAuth; token stored encrypted; uninstall webhook clears data.
- [ ] Merchant connects WooCommerce; Test Connection shows Woo/WP versions + counts; secrets encrypted & masked.
- [ ] Wizard previews available counts and starts a migration that runs entirely in queues.
- [ ] Products, Categories, Attributes, Variations, Media, Customers migrate correctly with mapping.
- [ ] Progress is live via polling; browser close/refresh/logout does not affect the run.
- [ ] Re-running a migration never duplicates records (verified by mapping table).
- [ ] Shopify 429s are respected; jobs retry with backoff; no data loss on transient errors.
- [ ] Failed items are listed with reasons and can be retried; migration can be cancelled.
- [ ] Migration history + details + logs available.
- [ ] Multi-tenant isolation verified; GDPR webhooks implemented.
- [ ] Tests green (unit + feature); Horizon configured for production.

---

## 14. Explicit Instructions for Claude (Build Rules)

1. **Design first, code second.** Confirm schema, contracts, and job graph before implementing an entity.
2. **Never run a migration in a controller/HTTP request.** Always enqueue.
3. **Every migrator must be idempotent** using `migration_items`. No exceptions.
4. **Use Shopify Admin GraphQL API**; avoid deprecated REST endpoints where GraphQL exists. Pin an API version.
5. **Chunk everything**; paginate Woo; batch Shopify writes.
6. **Handle 429/backoff/retry** on every external call.
7. **Encrypt credentials**; never return secrets to the client.
8. **Keep entities modular** behind the `Migrator` interface so new ones (orders, coupons) drop in cleanly.
9. **Don't hardcode plan limits**; read from config/plans (even while billing is disabled).
10. **Write tests** for transforms, idempotency, and failure paths.
