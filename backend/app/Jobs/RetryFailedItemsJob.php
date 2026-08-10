<?php

namespace App\Jobs;

use App\Migration\MigratorRegistry;
use App\Models\MigrationItem;
use App\Models\StoreMigration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Re-runs only the failed items of a migration. Idempotency ensures successful
 * items are never touched. For scaffolding this reuses the migrator directly per
 * failed item; a production version would re-batch by page.
 */
class RetryFailedItemsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public int $migrationId) {}

    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    public function handle(MigratorRegistry $registry): void
    {
        $migration = StoreMigration::with('connection', 'shop')->findOrFail($this->migrationId);

        $failed = MigrationItem::where('migration_id', $migration->id)
            ->where('status', 'failed')
            ->get();

        foreach ($failed as $item) {
            if (! $registry->has($item->entity_type)) {
                continue;
            }
            $migrator = $registry->make($item->entity_type);

            // Reset to pending so the migrator re-attempts.
            $item->update(['status' => 'pending', 'error' => null]);

            // NOTE: retrying by single id requires fetching the source item.
            // TODO: fetch single Woo item by id per entity; for now we mark it
            // pending so a subsequent full run/batch re-processes it idempotently.
        }

        if ($failed->isNotEmpty()) {
            $migration->update(['status' => 'processing']);
            StartMigrationJob::dispatch($migration->id);
        }
    }
}
