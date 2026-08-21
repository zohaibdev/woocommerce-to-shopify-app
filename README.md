# Shop Shift — WooCommerce → Shopify Migration App

An embedded Shopify app that migrates a merchant's WooCommerce store data into Shopify:
**Products, Categories→Collections, Attributes→Options, Variations→Variants, Images/Media, Customers.**

Migrations run as **chunked, idempotent, retryable, rate-limit-aware queue jobs** — never inside an HTTP request.

## Documents
- **[SPECIFICATION.md](SPECIFICATION.md)** — full production spec (architecture, schema, API contracts, engine, phases, Definition of Done).
- **[CLAUDE.md](CLAUDE.md)** — condensed build guardrails.

## Layout
```
backend/    Laravel 12 API + queue jobs + migration engine  (PHP 8.2, MySQL/Redis/Horizon)
frontend/   React 18 + Vite + TypeScript + Shopify Polaris embedded app
```

## Backend
```bash
cd backend
composer install
cp .env.example .env && php artisan key:generate   # if not already done
php artisan migrate
php artisan test                                    # engine + idempotency tests
php artisan queue:work                              # or `php artisan horizon` with Redis
php artisan serve
```
Key config: `config/migration.php` (batch size), `config/services.php` (`shopify.*`).
Default queue/DB use SQLite/sync for local dev; switch to **MySQL + Redis + Horizon** for production (see spec §2, §5.4, §14).

### Migration engine (backend/app/Migration)
- `Contracts/Migrator` — interface every entity implements.
- `AbstractMigrator` — template method enforcing the idempotency mapping for all migrators.
- `Migrators/*` — Product, Category, Customer (implemented); Attribute, Variation, Media (engine-wired, transforms marked `TODO(phase-7)`).
- `MigratorRegistry` — resolves + topologically orders entities by dependency.

### Jobs (backend/app/Jobs)
- `StartMigrationJob` → plans counts/batches, dispatches an ordered **chain** of...
- `ProcessBatchJob` → one chunk per job; idempotent; releases on Shopify 429 with backoff.
- `FinalizeMigrationJob` → rolls up final status.
- `RetryFailedItemsJob` → re-runs only failed items.

## Frontend
```bash
cd frontend
npm install --legacy-peer-deps
npm run dev     # set VITE_API_URL to the backend, e.g. http://localhost:8000/api
npm run build
```
Pages: Dashboard, WooCommerce connection, New-migration wizard, History, Details (live polling + failed-item retry).

## Status
MVP scaffold complete and green: DB schema, models, migration engine, queue jobs, REST API, Polaris UI, and passing tests for **idempotency, queue dispatch, and dependency ordering**.

### Done
- **Shopify OAuth install flow** — `/api/auth` + `/api/auth/callback` with HMAC verification, state nonce, code→token exchange, **encrypted** token storage, and `app/uninstalled` + GDPR webhooks (all HMAC-verified). Set `SHOPIFY_API_KEY`, `SHOPIFY_API_SECRET`, `SHOPIFY_APP_URL` in `.env`.

### Not yet done (see spec phases)
- App Bridge embedding + **session-token** API auth (frontend currently sends `X-Shop-Domain`, captured from the post-install `?shop=` param).
- Full transforms for Attributes/Variations/Media (Phase 7).
- Redis + Horizon wiring, plans/billing, email notifications, Orders (Phase 2+).
