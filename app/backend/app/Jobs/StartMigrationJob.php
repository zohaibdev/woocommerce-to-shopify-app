<?php

namespace App\Jobs;

use App\Migration\MigratorRegistry;
use App\Models\MigrationBatch;
use App\Models\MigrationEntity;
use App\Models\MigrationLog;
use App\Models\StoreMigration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Entry point for a migration. Plans every selected entity (counts + batch rows)
 * in dependency order, then dispatches a single sequential CHAIN of
 * ProcessBatchJob's. The chain guarantees dependency order (categories before
 * products, products before variations/media) while each job processes only one
 * page (chunk) to keep memory bounded. This job never migrates data itself.
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

        $migration->update(['status' => 'processing', 'started_at' => now()]);

        $perPage = (int) config('migration.per_page', 100);
        $ordered = $registry->order($migration->selected_entities);
        $chain = [];

        $shop = $migration->shop;

        foreach ($ordered as $entityType) {
            $migrator = $registry->make($entityType);
            $available = $migrator->count($migration->connection);

            // Plan limits: null = unlimited, 0 = feature not included.
            $limit = $shop->limitFor($entityType);
            $total = $limit === null ? $available : min($available, $limit);

            if ($limit !== null && $available > $limit) {
                MigrationLog::create([
                    'migration_id' => $migration->id,
                    'level' => 'warning',
                    'entity_type' => $entityType,
                    'message' => $limit === 0
                        ? "{$entityType} is not included in the ".($shop->currentPlan()?->name ?? 'current')." plan — skipped."
                        : "Plan limit reached: migrating {$limit} of {$available} {$entityType} records. Upgrade to migrate the rest.",
                    'created_at' => now(),
                ]);
            }

            MigrationEntity::updateOrCreate(
                ['migration_id' => $migration->id, 'entity_type' => $entityType],
                ['status' => $total > 0 ? 'processing' : 'completed', 'total_items' => $total],
            );

            $pages = (int) ceil($total / max(1, $perPage));
            for ($page = 1; $page <= $pages; $page++) {
                $batch = MigrationBatch::create([
                    'migration_id' => $migration->id,
                    'entity_type' => $entityType,
                    'batch_number' => $page,
                    'page' => $page,
                    'per_page' => $perPage,
                    'status' => 'pending',
                ]);
                $chain[] = new ProcessBatchJob($batch->id);
            }
        }

        // Append a finalizer that rolls up the overall migration status.
        $chain[] = new FinalizeMigrationJob($migration->id);

        $first = array_shift($chain);
        $first->chain($chain);
        dispatch($first);
    }
}
