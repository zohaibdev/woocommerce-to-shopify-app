<?php

namespace App\Migration;

use App\Jobs\FinalizeMigrationJob;
use App\Jobs\ProcessBatchJob;
use App\Models\MigrationBatch;
use App\Models\MigrationEntity;
use App\Models\MigrationItem;
use App\Models\MigrationLog;
use App\Models\StoreMigration;
use App\Services\ShopifyPlan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Drives a migration forward one batch at a time.
 *
 * Batches are NOT dispatched as a Bus chain any more: a chain dies the moment one
 * link fails, which used to leave the whole migration frozen at "processing" with
 * no error and no way to resume. Instead each batch, when it finishes — succeeded,
 * failed, or gave up — asks the runner for the next one. A migration therefore
 * always moves forward, and a permanently broken batch costs one batch, never the
 * run.
 */
class MigrationRunner
{
    /** Statuses that mean the run is over and must not be advanced any further. */
    public const TERMINAL = ['completed', 'completed_with_errors', 'failed', 'cancelled'];

    /**
     * Dispatch the outstanding batches, or finalize when there is none left.
     * Safe to call repeatedly — it only ever looks at persisted state.
     *
     * Parallelism happens WITHIN one entity, never across them: products may all
     * run at once, but they may not start until every category batch is done,
     * because a product references the collections categories created. So the run
     * fills as many slots as the shop's Shopify plan allows for whichever entity
     * it has currently reached, and no further.
     */
    public static function advance(int $migrationId): void
    {
        // Two batches finishing at the same instant both read the same in-flight
        // count and both hand out the same pending rows. claim() keeps that
        // CORRECT, but every loser still costs a queued job, a fetch and another
        // advance() — a dispatch storm on exactly the busiest shops. One caller
        // at a time per migration removes it. If the lock cannot be taken the
        // holder is doing this work right now, so there is nothing to miss.
        $lock = null;

        try {
            // Inside the try on purpose: on the database cache store this touches
            // the database, and a backend having a bad day must not be able to
            // stop a migration that is otherwise perfectly healthy.
            $lock = Cache::lock("migration:{$migrationId}:advance", 15);

            if (! $lock->get()) {
                return;
            }
        } catch (Throwable $e) {
            report($e);
            $lock = null;
        }

        try {
            self::dispatchNextBatches($migrationId);
        } finally {
            if ($lock) {
                try {
                    $lock->release();
                } catch (Throwable) {
                    // It expires on its own in fifteen seconds either way.
                }
            }
        }
    }

    private static function dispatchNextBatches(int $migrationId): void
    {
        $migration = StoreMigration::with('shop')->find($migrationId);

        if (! $migration || in_array($migration->status, self::TERMINAL, true) || $migration->status === 'paused') {
            return;
        }

        $next = self::nextBatch($migrationId);

        if (! $next) {
            // "No outstanding chunks" only means the run is over once every chunk
            // has been CREATED. Planning now runs alongside the migration (see
            // PlanBatchesJob), so a run whose first entity finished before the
            // second was planned would otherwise close itself as completed with
            // most of the catalogue never even listed.
            if ($migration->planned_at === null) {
                return;
            }

            FinalizeMigrationJob::dispatch($migrationId);

            return;
        }

        $entityType = $next->entity_type;
        $slots = self::concurrencyFor($migration, $entityType) - self::inFlight($migrationId, $entityType);

        if ($slots <= 0) {
            return;
        }

        $pending = MigrationBatch::where('migration_id', $migrationId)
            ->where('entity_type', $entityType)
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit($slots)
            ->get();

        // Nothing left to hand out for this entity — the workers still holding its
        // last batches will advance the run to the next entity when they finish.
        foreach ($pending as $batch) {
            ProcessBatchJob::dispatch($batch->id);
        }
    }

    /**
     * How many batches of this entity may be in flight for this shop.
     *
     * TWO limits, and the smaller wins.
     *
     * The first is the shop's REAL Shopify budget, learned from throttleStatus,
     * so a Plus merchant is not paced like a Standard one — the single biggest
     * reason a large catalogue took all night.
     *
     * The second is the per-entity ceiling, which exists because Shopify meters
     * orderCreate SEPARATELY from the GraphQL point budget and that limit is both
     * small and undocumented. It used to be one flat number for the whole
     * installation, so a Plus store migrated orders at exactly Basic speed; it is
     * now resolved per shop from ShopifyPlan::tier(). Nothing about the throttle
     * handling changed — a tier allowed more than Shopify will actually give it
     * simply backs off and settles at the real limit.
     */
    public static function concurrencyFor(StoreMigration $migration, string $entityType): int
    {
        $shop = $migration->shop;

        if (! $shop) {
            return 1;
        }

        $plan = app(ShopifyPlan::class);

        // Unchanged shape: the smaller of what the shop's API budget supports and
        // what this entity is separately allowed. Only the second number learned
        // to vary by plan.
        return max(1, min(
            $plan->concurrencyFor($shop),
            $plan->entityCeiling($shop, $entityType),
        ));
    }

    private static function inFlight(int $migrationId, string $entityType): int
    {
        return MigrationBatch::where('migration_id', $migrationId)
            ->where('entity_type', $entityType)
            ->where('status', 'processing')
            ->count();
    }

    /** The oldest batch that still has work left in it. */
    public static function nextBatch(int $migrationId): ?MigrationBatch
    {
        return MigrationBatch::where('migration_id', $migrationId)
            ->whereIn('status', ['pending', 'processing'])
            ->orderBy('id')
            ->first();
    }

    /**
     * Recompute an entity's counters from the mapping table.
     *
     * Counters used to be blind increment() calls, so any batch that ran twice
     * (worker killed mid-page, queue re-reservation) inflated them — which is how
     * a run could report "100/112 processed" while only 24 collections existed.
     * Deriving them from migration_items makes the numbers true no matter how
     * many times a batch is replayed.
     */
    public static function syncCounters(StoreMigration $migration, MigrationEntity $entity): void
    {
        $counts = MigrationItem::query()
            ->where('migration_id', $migration->id)
            ->where('entity_type', $entity->entity_type)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $successful = (int) ($counts['success'] ?? 0) + (int) ($counts['skipped'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0);
        $processed = $successful + $failed;

        // Never report more processed than planned (the plan cap is the total).
        if ($entity->total_items > 0) {
            $processed = min($processed, $entity->total_items);
        }

        $entity->update([
            'successful_items' => $successful,
            'failed_items' => $failed,
            'processed_items' => $processed,
        ]);
    }

    /** Mark an entity finished once every one of its batches is done. */
    public static function refreshEntityStatus(StoreMigration $migration, MigrationEntity $entity): void
    {
        $outstanding = MigrationBatch::where('migration_id', $migration->id)
            ->where('entity_type', $entity->entity_type)
            ->whereIn('status', ['pending', 'processing'])
            ->exists();

        if ($outstanding) {
            return;
        }

        $entity->refresh()->update([
            'status' => $entity->failed_items > 0 ? 'completed_with_errors' : 'completed',
        ]);
    }

    /**
     * Longest log line we will store. `message` is a TEXT column (65,535 bytes),
     * and a raw Shopify error set can be far longer than anything a merchant will
     * read — the useful part is always at the front.
     */
    private const MAX_MESSAGE_LENGTH = 8000;

    /**
     * Write one line of the migration's activity log.
     *
     * Deliberately incapable of throwing. This is called from inside the item
     * loop, OUTSIDE the try/catch that turns an item error into an item failure —
     * so before the message was truncated, a single over-long Shopify error took
     * down the entire batch job instead of the one record it belonged to.
     */
    public static function log(int $migrationId, string $level, ?string $entityType, string $message, ?array $context = null): void
    {
        try {
            MigrationLog::create([
                'migration_id' => $migrationId,
                'level' => $level,
                'entity_type' => $entityType,
                'message' => Str::limit($message, self::MAX_MESSAGE_LENGTH),
                'context' => $context,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // The run matters; its diary does not.
            report($e);
        }
    }
}
