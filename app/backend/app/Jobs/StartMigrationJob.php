<?php

namespace App\Jobs;

use App\Migration\MigrationRunner;
use App\Migration\MigratorRegistry;
use App\Models\MigrationBatch;
use App\Models\MigrationEntity;
use App\Models\StoreMigration;
use App\Services\ShopifyPlan;
use App\Support\MigrationDiagnostics;
use App\Support\PlanPool;
use App\Support\SourceHealth;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Entry point for a migration. Plans every selected entity (counts + batch rows)
 * in dependency order, then kicks off the FIRST batch. Each batch dispatches the
 * next one when it finishes (see MigrationRunner), which is what keeps a failing
 * batch from killing the run — a Bus chain used to stop dead at the first failure
 * and leave the migration stuck at "processing" forever.
 *
 * This job never migrates data itself.
 */
class StartMigrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Planning is the longest single job in the whole engine, and it had no
     * timeout of its own — so it inherited the worker's default of SIXTY SECONDS,
     * while every other job here sets its own. That default is nowhere near
     * enough for a large store:
     *
     *   - one count() per entity, each an HTTP call the client gives 60s and three
     *     retries of its own, and
     *   - when the store does not send X-WP-Total (a security plugin, a caching
     *     proxy, Cloudflare — all common), counting falls back to probing for the
     *     last page: ~2·log₂(pages) requests, and those probes are deliberately
     *     the DEEPEST, slowest pages on the site.
     *
     * Four entities on a 50k catalogue is minutes, not seconds. The job was killed
     * at 60s, retried three times, and then failed() marked the migration failed —
     * and the watchdog only sweeps pending/processing runs, so nothing ever picked
     * it up again. The largest merchants were the ones who could never start.
     *
     * Must stay under the queue's retry_after (900s) so the queue never decides
     * this job is dead and runs a second copy of the planner.
     */
    public int $timeout = 600;

    public function __construct(public int $migrationId) {}

    public function handle(MigratorRegistry $registry): void
    {
        $migration = StoreMigration::with('connection', 'shop')->findOrFail($this->migrationId);

        if ($migration->isCancelledOrPaused()) {
            return;
        }

        $migration->update([
            'status' => 'processing',
            'started_at' => $migration->started_at ?? now(),
            // Planning starts again from nothing below, so the run must not be
            // allowed to finalize until PlanBatchesJob says so. A re-plan that
            // left this set would let a half-planned run close itself the moment
            // its first chunk finished.
            'planned_at' => null,
        ]);

        // Re-planning (a retry-failed run) must not stack a second set of batches on
        // top of the old ones — migration_items still guarantees idempotency.
        MigrationBatch::where('migration_id', $migration->id)->delete();

        $ordered = $registry->order($migration->selected_entities);
        $shop = $migration->shop;

        // Attribute the WooCommerce counting requests below to this migration, so
        // a store that is already struggling is reported before the merchant has
        // watched a dead-looking page for two minutes.
        SourceHealth::within($migration->id);
        MigrationDiagnostics::begin($migration->id, null, 'start / counting');

        // Work out how hard we are allowed to push THIS merchant's Shopify store.
        // The plan name is cosmetic; the numbers that matter (bucket size, restore
        // rate) are learned from the response to this very call.
        $plan = app(ShopifyPlan::class);
        $plan->detect($shop);
        MigrationRunner::log($migration->id, 'info', null, $plan->describe($shop->refresh()));

        // Counting is several WooCommerce round-trips before any progress bar can
        // move. Say so, otherwise the first minute looks like a dead page.
        MigrationRunner::log($migration->id, 'info', null, 'Reading your WooCommerce store…');

        // One shared record budget for the whole run, drawn down entity by entity
        // below. Built once so the allocation is consistent across them.
        $pool = PlanPool::for($shop);

        // Said once, up front, rather than leaving the merchant to notice that
        // their custom fields never arrived. A silently reduced migration is the
        // thing that makes people distrust a migration tool.
        if (in_array('product', $migration->selected_entities ?? [], true) && ! $shop->planAllows('metafields')) {
            MigrationRunner::log(
                $migration->id,
                'warning',
                'product',
                'Your '.($shop->currentPlan()?->name ?? 'current').' plan does not include metafields and brands, '
                    .'so custom WooCommerce fields and brand data will not be copied. Everything else about each '
                    .'product still migrates — upgrade and re-run to add them.',
            );
        }

        // The single most alarming thing an order migration does to a merchant's
        // inbox, and the app cannot stop it: `sendReceipt: false` on orderCreate
        // suppresses the CUSTOMER's order confirmation (see OrderMigrator), but
        // Shopify's own "New order" STAFF notification is a store setting with no
        // Admin API surface at all. Migrating 2,600 historical orders therefore
        // delivers 2,600 emails to the store owner unless they turn it off first.
        // Saying so up front is the only remedy that exists.
        if (in_array('order', $migration->selected_entities ?? [], true)) {
            MigrationRunner::log(
                $migration->id,
                'warning',
                'order',
                'Your customers will NOT be emailed about these orders — order confirmations are suppressed. '
                    .'However, Shopify sends YOU a "New order" staff notification for every order created, and '
                    .'that setting is not something an app can change. To avoid filling your inbox, turn it off in '
                    .'Shopify admin → Settings → Notifications → Staff notifications → New order, and turn it back '
                    .'on when this migration finishes.',
            );
        }

        if ($pool->isActive()) {
            MigrationRunner::log(
                $migration->id,
                'info',
                null,
                'Your '.($shop->currentPlan()?->name ?? 'current').' plan covers '
                    .number_format((int) $pool->limit).' records in total across products, customers and orders — '
                    .number_format((int) $pool->remaining()).' still available.',
            );
        }

        foreach ($ordered as $entityType) {
            $migrator = $registry->make($entityType);

            try {
                $available = $migrator->count($migration->connection);
            } catch (Throwable $e) {
                // A source that can't be counted must not abort the other entities.
                MigrationRunner::log(
                    $migration->id,
                    'error',
                    $entityType,
                    "Could not read {$entityType} count from WooCommerce: {$e->getMessage()}",
                );

                MigrationEntity::updateOrCreate(
                    ['migration_id' => $migration->id, 'entity_type' => $entityType],
                    ['status' => 'failed', 'total_items' => 0],
                );

                continue;
            }

            // Plan limits: null = unlimited, 0 = feature not included in the plan.
            $limit = $shop->limitFor($entityType);
            $total = $limit === null ? $available : min($available, $limit);

            if ($limit !== null && $available > $limit) {
                MigrationRunner::log(
                    $migration->id,
                    'warning',
                    $entityType,
                    $limit === 0
                        ? "{$entityType} is not included in the ".($shop->currentPlan()?->name ?? 'current').' plan — skipped. Upgrade your plan to migrate it.'
                        : "Plan limit reached: migrating {$limit} of {$available} {$entityType} records. Upgrade to migrate the rest.",
                );
            }

            // The SHARED record budget, spent across products, customers and
            // orders together. Claimed here in dependency order, which is the same
            // order the preview claimed it in — so what the wizard promised is
            // what this plans. See App\Support\PlanPool.
            if ($pool->meters($entityType) && $limit !== 0) {
                $beforePool = $total;
                $total = $pool->claim($entityType, $total);

                if ($total < $beforePool) {
                    MigrationRunner::log(
                        $migration->id,
                        'warning',
                        $entityType,
                        'Plan allowance reached: your '.($shop->currentPlan()?->name ?? 'current').' plan covers '
                            .number_format((int) $pool->limit).' records in total across products, customers and orders. '
                            ."Migrating {$total} of {$available} {$entityType} records — talk to support if you need more.",
                    );
                }
            }

            MigrationEntity::updateOrCreate(
                ['migration_id' => $migration->id, 'entity_type' => $entityType],
                [
                    'status' => $total > 0 ? 'processing' : 'completed',
                    'total_items' => $total,
                    // Planning progress starts from zero on every (re-)plan; the
                    // batch rows it refers to were just deleted above.
                    'planned_items' => 0,
                    'last_source_id' => null,
                ],
            );

            MigrationRunner::log(
                $migration->id,
                'info',
                $entityType,
                $total > 0
                    ? "Found {$available} {$entityType} records — migrating {$total}."
                    : "Nothing to migrate for {$entityType}.",
            );

        }

        // Chunks are no longer created here. PlanBatchesJob walks each entity's
        // ids and pins them to chunks in one continuous pass — see that job for
        // why the old page-number addressing quietly lost records on a run long
        // enough for the merchant to delete something. It creates the chunks in
        // dependency order (the same order used above) and hands them to the
        // runner as it goes, so work starts within seconds rather than waiting
        // for the whole catalogue to be listed.
        PlanBatchesJob::dispatch($migration->id);
        MigrationDiagnostics::finish('counting complete, planning dispatched');
    }

    /** Never leave a migration stranded in "pending" because planning blew up. */
    public function failed(?Throwable $e): void
    {
        MigrationRunner::log(
            $this->migrationId,
            'error',
            null,
            'Could not start the migration: '.($e?->getMessage() ?? 'unknown error'),
        );

        StoreMigration::where('id', $this->migrationId)
            ->whereIn('status', ['pending', 'processing'])
            ->update(['status' => 'failed', 'completed_at' => now()]);
    }
}
