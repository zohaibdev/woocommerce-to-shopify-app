<?php

namespace App\Jobs;

use App\Migration\MigrationRunner;
use App\Migration\MigratorRegistry;
use App\Models\MigrationBatch;
use App\Models\MigrationEntity;
use App\Models\StoreMigration;
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

    public function __construct(public int $migrationId) {}

    public function handle(MigratorRegistry $registry): void
    {
        $migration = StoreMigration::with('connection', 'shop')->findOrFail($this->migrationId);

        if ($migration->isCancelledOrPaused()) {
            return;
        }

        $migration->update(['status' => 'processing', 'started_at' => $migration->started_at ?? now()]);

        // Re-planning (a retry-failed run) must not stack a second set of batches on
        // top of the old ones — migration_items still guarantees idempotency.
        MigrationBatch::where('migration_id', $migration->id)->delete();

        $configuredPerPage = (int) config('migration.per_page', 100);
        $ordered = $registry->order($migration->selected_entities);
        $shop = $migration->shop;

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

            MigrationEntity::updateOrCreate(
                ['migration_id' => $migration->id, 'entity_type' => $entityType],
                ['status' => $total > 0 ? 'processing' : 'completed', 'total_items' => $total],
            );

            if ($total <= 0) {
                continue;
            }

            // Never page wider than the plan allows — otherwise a 10-product plan
            // would fetch (and migrate) a full 100-item page.
            $perPage = min($configuredPerPage, $total);
            $pages = (int) ceil($total / $perPage);

            for ($page = 1; $page <= $pages; $page++) {
                MigrationBatch::create([
                    'migration_id' => $migration->id,
                    'entity_type' => $entityType,
                    'batch_number' => $page,
                    'page' => $page,
                    'per_page' => $perPage,
                    'processed_offset' => 0,
                    'status' => 'pending',
                ]);
            }
        }

        // Batches were created in dependency order, so advancing by id preserves it.
        MigrationRunner::advance($migration->id);
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
