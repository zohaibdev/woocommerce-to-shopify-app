<?php

namespace App\Jobs;

use App\Migration\MigrationItemResult;
use App\Migration\MigrationRunner;
use App\Migration\MigratorRegistry;
use App\Models\MigrationEntity;
use App\Models\MigrationItem;
use App\Models\StoreMigration;
use App\Services\Exceptions\ShopifyThrottledException;
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

        $failed = MigrationItem::where('migration_id', $migration->id)
            ->where('status', 'failed')
            ->get()
            ->groupBy('entity_type');

        if ($failed->isEmpty()) {
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

            if ($result->status === 'failed') {
                MigrationRunner::log($migration->id, 'error', $entityType, "Retry of item {$wooId} failed: {$result->error}");
            } elseif ($result->warning) {
                MigrationRunner::log($migration->id, 'warning', $entityType, "Item {$wooId}: {$result->warning}");
            } else {
                MigrationRunner::log($migration->id, 'info', $entityType, "Item {$wooId} migrated on retry.");
            }
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
