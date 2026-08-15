<?php

namespace App\Jobs;

use App\Migration\MigrationItemResult;
use App\Migration\MigrationRunner;
use App\Migration\MigratorRegistry;
use App\Models\MigrationBatch;
use App\Models\MigrationEntity;
use App\Models\MigrationItem;
use App\Models\StoreMigration;
use App\Services\Exceptions\ShopifyThrottledException;
use App\Support\SourceHealth;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Re-runs ONLY the failed items of a migration.
 *
 * This used to just flip the failed rows back to pending and re-dispatch
 * StartMigrationJob, which re-planned and re-walked every entity from page one.
 * On a large catalogue that meant a full re-run to retry a handful of records —
 * slow enough that it looked like the button did nothing.
 *
 * Now the failed WooCommerce ids are fetched directly (one request per chunk) and
 * re-migrated. Same time budget and resumability as ProcessBatchJob, so it can
 * never hang the queue either.
 */
class RetryFailedItemsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Same reasoning as ProcessBatchJob: one slow record must not kill the job. */
    public int $timeout = 300;

    private const TIME_BUDGET_SECONDS = 35;

    /** WooCommerce ids fetched per request. */
    private const CHUNK = 25;

    public function __construct(public int $migrationId) {}

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(MigratorRegistry $registry): void
    {
        $migration = StoreMigration::with('connection', 'shop')->find($this->migrationId);

        if (! $migration || $migration->isCancelledOrPaused()) {
            return;
        }

        SourceHealth::within($migration->id);

        // Whole batches that never got read come first — see reviveFailedBatches().
        $this->reviveFailedBatches($migration);

        // Only the entity type and source id are needed to re-fetch from
        // WooCommerce. Hydrating whole models here put every failed row of a large
        // run into one job's memory at once.
        $failed = MigrationItem::where('migration_id', $migration->id)
            ->where('status', 'failed')
            ->select('entity_type', 'woocommerce_id')
            ->get()
            ->groupBy('entity_type');

        if ($failed->isEmpty()) {
            // Finalize hands straight back to the runner when batches are
            // outstanding, so a run whose only problem was failed batches starts
            // moving again from here.
            FinalizeMigrationJob::dispatch($migration->id);

            return;
        }

        $startedAt = microtime(true);

        // Dependency order still matters: a failed product may need a category
        // that failed alongside it.
        foreach ($registry->order($failed->keys()->all()) as $entityType) {
            if (! $registry->has($entityType)) {
                continue;
            }

            $migrator = $registry->make($entityType);
            $entity = MigrationEntity::firstOrCreate(
                ['migration_id' => $migration->id, 'entity_type' => $entityType],
            );

            $ids = $failed[$entityType]->pluck('woocommerce_id')->all();

            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                if ($migration->isCancelledOrPaused()) {
                    return;
                }

                if (! $this->retryChunk($migration, $migrator, $entity, $entityType, $chunk)) {
                    return; // throttled — a fresh job was queued to carry on
                }

                MigrationRunner::syncCounters($migration, $entity);

                // Out of time: come back in a new job. Items already retried are
                // no longer 'failed', so the next pass picks up where this left off.
                if (microtime(true) - $startedAt >= self::TIME_BUDGET_SECONDS) {
                    self::dispatch($migration->id);

                    return;
                }
            }

            MigrationRunner::refreshEntityStatus($migration, $entity);
        }

        FinalizeMigrationJob::dispatch($migration->id);
    }

    /**
     * Put failed BATCHES back in the queue.
     *
     * A batch fails as a whole when its WooCommerce page could not be read at all
     * — the deep-offset timeout that a large catalogue produces, a host that went
     * away mid-run. When that happens the item loop never ran, so those records
     * have no rows in migration_items: not as failed, not as anything.
     *
     * That made them invisible to every recovery path there is. The counters could
     * not see them, this job's failed-item query returned nothing, and the log
     * download printed "FAILED ITEMS (0)" while a hundred products were missing
     * from the merchant's store. The only remedy was re-running the entire
     * migration, and nothing in the app said so.
     *
     * So the batch itself is the unit that gets retried. `processed_offset` is
     * left exactly where it was, so a batch that failed halfway resumes rather
     * than replaying; `attempts` is reset because this is a deliberate, merchant-
     * initiated fresh start rather than another automatic go.
     */
    private function reviveFailedBatches(StoreMigration $migration): void
    {
        $failedBatches = MigrationBatch::where('migration_id', $migration->id)
            ->where('status', 'failed')
            ->get();

        if ($failedBatches->isEmpty()) {
            return;
        }

        foreach ($failedBatches as $batch) {
            $batch->update([
                'status' => 'pending',
                'attempts' => 0,
                'error' => null,
            ]);
        }

        $byEntity = $failedBatches
            ->groupBy('entity_type')
            ->map(fn ($rows, $type) => $rows->count()." {$type}")
            ->implode(', ');

        MigrationRunner::log(
            $migration->id,
            'info',
            null,
            "Re-queued {$failedBatches->count()} batch(es) whose records were never read from WooCommerce ({$byEntity}).",
        );
    }

    /** @return bool false when the chunk was postponed for throttling */
    private function retryChunk(
        StoreMigration $migration,
        $migrator,
        MigrationEntity $entity,
        string $entityType,
        array $ids,
    ): bool {
        try {
            $items = $migrator->fetchByIds($migration->connection, $ids);
        } catch (Throwable $e) {
            MigrationRunner::log($migration->id, 'error', $entityType, "Retry could not read WooCommerce: {$e->getMessage()}");

            return true; // skip this chunk, keep going
        }

        // Anything WooCommerce no longer returns has been deleted at the source.
        // Retrying it forever helps nobody — say so and stop counting it as work.
        $returned = collect($items)->map(fn ($i) => $migrator->sourceId($i))->all();

        foreach (array_diff($ids, $returned) as $missing) {
            MigrationItem::where('migration_id', $migration->id)
                ->where('entity_type', $entityType)
                ->where('woocommerce_id', $missing)
                ->update(['status' => 'skipped', 'error' => 'No longer exists in WooCommerce']);

            MigrationRunner::log($migration->id, 'warning', $entityType, "Item {$missing} no longer exists in WooCommerce — skipped.");
        }

        // Same bulk lookups a normal batch gets — without this a retried product
        // would ship full-size images even though the merchant asked for smaller.
        if (method_exists($migrator, 'prefetchPage')) {
            $migrator->prefetchPage($migration, $items);
        }

        $recovered = 0;

        foreach ($items as $wooItem) {
            $wooId = $migrator->sourceId($wooItem);

            try {
                $result = $migrator->migrateItem($migration, $wooItem);
            } catch (ShopifyThrottledException $e) {
                self::dispatch($migration->id)->delay((int) ceil($e->retryAfterSeconds));

                return false;
            } catch (Throwable $e) {
                $result = MigrationItemResult::failed($e->getMessage());
            }

            // Only failures and warnings get a line of their own. A per-item
            // "migrated on retry" note meant retrying a 50,000-record run wrote
            // 50,000 log rows nobody reads — the recovered count below says the
            // same thing in one line.
            if ($result->status === 'failed') {
                MigrationRunner::log($migration->id, 'error', $entityType, "Retry of item {$wooId} failed: {$result->error}");
            } elseif ($result->warning) {
                MigrationRunner::log($migration->id, 'warning', $entityType, "Item {$wooId}: {$result->warning}");
            } else {
                $recovered++;
            }
        }

        if ($recovered > 0) {
            MigrationRunner::log($migration->id, 'info', $entityType, "{$recovered} item(s) migrated on retry.");
        }

        return true;
    }

    public function failed(?Throwable $e): void
    {
        MigrationRunner::log(
            $this->migrationId,
            'error',
            null,
            'Retry failed: '.($e?->getMessage() ?? 'unknown error'),
        );

        FinalizeMigrationJob::dispatch($this->migrationId);
    }
}
