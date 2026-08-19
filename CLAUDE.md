# CLAUDE.md — Shift Shop (WooCommerce → Shopify Migration App)

> **START HERE:** read **[PROJECT-CONTEXT.json](PROJECT-CONTEXT.json)** first. It is the
> machine-readable snapshot of everything built so far — architecture, folder layout,
> migrators, plans/limits, auth, known gotchas, live blockers and commands. It exists so a
> new session doesn't have to re-explore the codebase.
>
> Human-readable companions, kept in step with it:
> **[PROJECT_CONTEXT.md](PROJECT_CONTEXT.md)** (orientation + business rules),
> **[ARCHITECTURE.md](ARCHITECTURE.md)** (full technical walk-through, migration deep dive),
> **[COMMANDS.md](COMMANDS.md)** (every command, verified against this repo).
> Changing behaviour means updating the JSON **and** the affected .md — a contradiction
> between them is worse than either being stale.

Read **[SPECIFICATION.md](SPECIFICATION.md)** before doing any work. It is the source of truth for architecture, schema, API contracts, and phases. This file is the short guardrail list.

## What this project is
Embedded Shopify app (Laravel 11 + MySQL + Redis/Horizon backend; React + Vite + TypeScript + Shopify Polaris frontend) that migrates a merchant's **WooCommerce** store data into their **Shopify** store: Products, Categories→Collections, Attributes→Options, Variations→Variants, Images/Media, Customers. Orders and billing are Phase 2.

## Non-negotiable rules
1. **Migrations run in queues, never in an HTTP request.** Controllers only enqueue and report status.
2. **Everything is chunked** — paginate WooCommerce (`page`/`per_page`), batch Shopify writes (100–250/batch, one batch = one job).
3. **Every migrator is idempotent** via the `migration_items` mapping table (unique `shop_id + entity_type + woocommerce_id`). Look up before create; skip if already mapped.
4. **Respect rate limits** — handle Shopify `429`/GraphQL `THROTTLED` with `Retry-After`/`throttleStatus`; retry with exponential backoff (`$tries=5`).
5. **Database is the source of truth.** Frontend polls status; never holds migration state. Browser close/refresh/logout must not affect a run.
6. **Encrypt all credentials** (Woo key/secret, Shopify token) at rest; never return secrets to the client — mask them.
7. **Multi-tenant:** every query scoped by `shop_id`.
8. **Modular engine:** each entity implements the `Migrator` interface; new entities plug in without touching the core.
9. **Use Shopify Admin GraphQL API** (pinned version); avoid deprecated REST where GraphQL exists.
10. **Don't hardcode plan limits** — read from config/plans even while billing is off.

## Layout
```
backend/    Laravel API, queue jobs, migrators
frontend/   React + Vite + Polaris embedded app
SPECIFICATION.md   full spec
```

## Before implementing an entity
Confirm: DB mapping, transform (Woo→Shopify input), dependency order, idempotency check, failure handling (record failed item + log, don't abort the migration).

## Testing
Write tests for: each transform (fixtures), idempotency (double-run = one record + SKIPPED), failure paths (429 → release/backoff; item error → recorded failed, migration continues). Use `Http::fake()` and `Queue::fake()`.

## Commands (once scaffolded)
```bash
# backend
cd backend && composer install && php artisan migrate && php artisan horizon
# frontend
cd frontend && npm install && npm run dev
```
