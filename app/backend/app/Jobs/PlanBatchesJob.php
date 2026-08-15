<?php

namespace App\Jobs;

use App\Migration\MigrationRunner;
use App\Migration\MigratorRegistry;
use App\Models\MigrationBatch;
use App\Models\MigrationEntity;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use App\Support\SourceHealth;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Decides, up front, exactly which WooCommerce records each chunk owns.
 *
 * A chunk used to be addressed by PAGE NUMBER until its first job ran, and only
 * then pinned to concrete ids. Page/offset paging is not stable across a delete:
 * remove one record and every later page shifts one place left, so records on
 * the boundary between "already pinned" and "not pinned yet" are simply never
 * fetched. They get no row in migration_items at all, which makes them invisible
 * to the counters, to the failed-items list and to "Retry failed items" — a run
 * could deliver 19,950 of 20,000 products and report success.
 *
 * The window for that was the WHOLE RUN. Hours, during which a merchant tidying
 * their catalogue is completely normal behaviour. Doing the listing in one pass
 * at the start collapses it to the length of THIS job, and once a chunk is
 * pinned nothing can move it.
 *
 * Three properties make that affordable:
 *
 *  1. CHEAP — `_fields=id` skips the expensive half of a WooCommerce page (the
 *     images, meta_data, attributes and variation links), so listing 20,000
 *     products is 200 small requests rather than 200 full ones.
 *  2. RESUMABLE — progress lives on the entity row (planned_items,
 *     last_source_id), so a worker killed mid-plan picks up where it stopped
 *     instead of starting the catalogue again.
 *  3. NOT A GATE — every chunk is handed to the runner the moment it exists, so
 *     the migration starts moving within seconds. Planning and migrating run
 *     alongside each other; `planned_at` is what stops the run closing itself
 *     while the planner is still working.
 *
 * WHAT THIS DOES NOT DO: it is not a snapshot. A record deleted DURING this pass
 * can still slip between two pages. That window is minutes rather than hours,
 * and a shortfall is reported rather than swallowed — but a source that could be
 * read atomically would need something WooCommerce's REST API does not offer.
 */
class PlanBatchesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Same reasoning as ProcessBatchJob: under the queue's retry_after (900). */
    public int $timeout = 300;

    /** Stop after this long and continue in a fresh job. */
    private const TIME_BUDGET_SECONDS = 35;

    /**
     * Pages listed between hand-offs to the runner.
     *
     * advance() takes a lock and runs a few queries, so calling it per page on a
     * 500-page catalogue is real work for nothing — once the concurrency slots
     * are full it has nothing to do anyway. Every few pages keeps the queue fed
     * without turning planning into a dispatch storm.
     */
    private const ADVANCE_EVERY_PAGES = 5;

    /**
     * Consecutive pages that pinned NOTHING before this entity is closed off.
     *
     * A page can legitimately pin nothing: every id on it was already claimed by
     * an earlier chunk, which is what happens when a record is inserted mid-
     * listing and shifts the remaining pages right. But a source that keeps
     * answering with ids we have already seen — a listing that ignores `page`, a
     * cache serving one page to every request — would otherwise be walked for
     * ever, and the planner has no natural end to reach.
     */
    private const MAX_BARREN_PAGES = 3;

    /** What one page of listing achieved. */
    private const PAGE_PINNED = 'pinned';

    private const PAGE_BARREN = 'barren';

    private const PAGE_DONE = 'done';

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function __construct(public int $migrationId) {}

    public function handle(MigratorRegistry $registry): void
    {
        $migration = StoreMigration::with('connection', 'shop')->find($this->migrationId);

        if (! $migration || $migration->isCancelledOrPaused()) {
            return;
        }

        // Already planned. Safe to arrive here twice — the watchdog re-dispatches
        // this whenever planning looks stalled, and "stalled" and "just finished"
        // are indistinguishable from the outside.
        if ($migration->planned_at) {
            MigrationRunner::advance($migration->id);

            return;
        }

        SourceHealth::within($migration->id);

        $startedAt = microtime(true);
        $perPage = max(1, (int) config('migration.per_page', 100));
        $pagesSinceAdvance = 0;

        foreach ($registry->order($migration->selected_entities ?? []) as $entityType) {
            $entity = MigrationEntity::where('migration_id', $migration->id)
                ->where('entity_type', $entityType)
                ->first();

            // No entity row, or nothing to migrate, or already fully pinned.
            if (! $entity || $entity->total_items <= 0 || $entity->planned_items >= $entity->total_items) {
                continue;
            }

            $migrator = $registry->make($entityType);

            // The page number is carried in the loop rather than re-derived from
            // the chunks each time. Deriving it was a live lock: a page that
            // pinned nothing created no chunk, so `max(page) + 1` came back with
            // the SAME page, which listed the same ids, which pinned nothing…
            // for ever, at one WooCommerce request per iteration. Resuming still
            // derives it once, below.
            $page = (int) MigrationBatch::where('migration_id', $migration->id)
                ->where('entity_type', $entityType)
                ->max('page') + 1;

            $barren = 0;

            while ($entity->planned_items < $entity->total_items) {
                if ($migration->isCancelledOrPaused()) {
                    return;
                }

                $outcome = $this->planOnePage($migrator, $migration, $entity, $perPage, $page);

                if (++$pagesSinceAdvance >= self::ADVANCE_EVERY_PAGES) {
                    $pagesSinceAdvance = 0;
                    MigrationRunner::advance($migration->id);
                }

                if ($outcome === self::PAGE_DONE) {
                    break;
                }

                $barren = $outcome === self::PAGE_BARREN ? $barren + 1 : 0;

                if ($barren >= self::MAX_BARREN_PAGES) {
                    $this->giveUpListing($migration, $entity, $page + 1, $perPage);

                    break;
                }

                $page++;

                if (microtime(true) - $startedAt >= self::TIME_BUDGET_SECONDS) {
                    self::dispatch($migration->id);
                    MigrationRunner::advance($migration->id);

                    return;
                }
            }
        }

        // Every entity is pinned. Only now may the run be allowed to finish.
        $migration->update(['planned_at' => now()]);

        MigrationRunner::log(
            $migration->id,
            'info',
            null,
            'Finished working out exactly which records to migrate.',
        );

        MigrationRunner::advance($migration->id);
    }

    /**
     * Pin one page of ids to a new chunk.
     *
     * @return string PAGE_PINNED, PAGE_BARREN (nothing new on it) or PAGE_DONE
     */
    private function planOnePage($migrator, StoreMigration $migration, MigrationEntity $entity, int $perPage, int $page): string
    {
        try {
            $ids = $this->listIds($migrator, $migration->connection, $page, $perPage);
        } catch (Throwable $e) {
            // The listing itself failed. Fall back to page-addressed chunks for
            // whatever is left: slower and still deletion-sensitive, but it is
            // what every migrator did before this job existed, and a source that
            // cannot be listed must not cost the merchant the entity.
            MigrationRunner::log(
                $migration->id,
                'warning',
                $entity->entity_type,
                "Could not list {$entity->entity_type} ids from WooCommerce ({$e->getMessage()}) — "
                    .'falling back to reading it page by page.',
            );

            $this->planRemainderByPage($migration, $entity, $page, $perPage);

            return self::PAGE_DONE;
        }

        if ($ids === null) {
            // This source will not answer an id-only listing (older WooCommerce,
            // a wp/v2 endpoint behind a filter). Not an error, just the old path.
            $this->planRemainderByPage($migration, $entity, $page, $perPage);

            return self::PAGE_DONE;
        }

        // Ids come back ascending, so anything at or below the highest already
        // pinned belongs to an earlier chunk. This is what stops a record
        // INSERTED mid-listing — which shifts the remaining pages right — being
        // pinned into two chunks and migrated twice.
        $lastId = (int) ($entity->last_source_id ?? 0);
        $fresh = array_values(array_filter($ids, fn (int $id) => $id > $lastId));

        // Never pin more than the plan allows. total_items is already capped by
        // StartMigrationJob against both the per-entity limit and the shared pool.
        $room = max(0, (int) $entity->total_items - (int) $entity->planned_items);
        $take = array_slice($fresh, 0, $room);

        if (! empty($take)) {
            MigrationBatch::create([
                'migration_id' => $migration->id,
                'entity_type' => $entity->entity_type,
                'batch_number' => $page,
                'page' => $page,
                'per_page' => $perPage,
                'source_ids' => $take,
                'processed_offset' => 0,
                'status' => 'pending',
            ]);

            $entity->forceFill([
                'planned_items' => (int) $entity->planned_items + count($take),
                'last_source_id' => max($take),
            ])->save();
        }

        if (count($ids) < $perPage) {
            // End of the listing. Two very different endings, and telling them
            // apart is the difference between a working migration and one that
            // reports success having moved nothing.
            //
            // NOTHING AT ALL was listed, yet the store said it has records: that
            // is a source whose id listing we cannot use — it ignored `_fields`,
            // or answered the count from a cache, or filters the two endpoints
            // differently. Trusting the listing here would set the total to zero
            // and finish the entity in seconds with a clean green tick. So fall
            // back to reading it page by page, which is what worked before this
            // job existed.
            if ((int) $entity->planned_items === 0) {
                MigrationRunner::log(
                    $migration->id,
                    'warning',
                    $entity->entity_type,
                    "Your store reports {$entity->total_items} {$entity->entity_type} records but would not list "
                        .'them by id, so they are being read page by page instead. The migration still runs; it is '
                        .'just more sensitive to records being added or deleted while it does.',
                );

                $this->planRemainderByPage($migration, $entity, $page, $perPage);

                return self::PAGE_DONE;
            }

            // Some were listed and then the source ran out: the count was stale,
            // or the store shrank between counting and listing. Bring the total
            // down so the progress bar can actually reach 100% instead of
            // stopping a few records short with nothing to explain it.
            $this->closeShort($migration, $entity);

            return self::PAGE_DONE;
        }

        if ($entity->planned_items >= $entity->total_items) {
            return self::PAGE_DONE;
        }

        return empty($take) ? self::PAGE_BARREN : self::PAGE_PINNED;
    }

    /**
     * The listing kept answering with records we had already pinned.
     *
     * Not a source we can keep walking, so take what was pinned as the truth if
     * there is any, and otherwise hand the rest back to plain page paging. The
     * one outcome not permitted is leaving the entity with a total it can never
     * reach, which shows the merchant a progress bar frozen short of the end
     * with nothing anywhere to explain it.
     */
    private function giveUpListing(StoreMigration $migration, MigrationEntity $entity, int $nextPage, int $perPage): void
    {
        MigrationRunner::log(
            $migration->id,
            'warning',
            $entity->entity_type,
            "Your store stopped listing new {$entity->entity_type} ids — it kept returning records already "
                .'accounted for. Working with what it did give us.',
        );

        if ((int) $entity->planned_items === 0) {
            $this->planRemainderByPage($migration, $entity, $nextPage, $perPage);

            return;
        }

        $this->closeShort($migration, $entity);
    }

    /**
     * @return array<int, int>|null null when this source cannot be listed by id
     */
    private function listIds($migrator, WoocommerceConnection $connection, int $page, int $perPage): ?array
    {
        if (! method_exists($migrator, 'listSourceIds')) {
            return null;
        }

        $ids = $migrator->listSourceIds($connection, $page, $perPage);

        if ($ids === null) {
            return null;
        }

        // De-duplicate within the page as well as across pages: a repeated id
        // would inflate the chunk's length and leave processed_offset pointing
        // past the end of the real work.
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Plan what is left the old way — page number and offset, no pinned ids.
     *
     * ProcessBatchJob still understands a batch with no source_ids, so this is a
     * complete fallback rather than a degraded one. It is simply exposed to the
     * shifting-page problem this job exists to shrink.
     */
    private function planRemainderByPage(StoreMigration $migration, MigrationEntity $entity, int $fromPage, int $perPage): void
    {
        $remaining = max(0, (int) $entity->total_items - (int) $entity->planned_items);

        if ($remaining <= 0) {
            return;
        }

        $pages = (int) ceil($remaining / $perPage);

        for ($i = 0; $i < $pages; $i++) {
            MigrationBatch::create([
                'migration_id' => $migration->id,
                'entity_type' => $entity->entity_type,
                'batch_number' => $fromPage + $i,
                'page' => $fromPage + $i,
                'per_page' => $perPage,
                'processed_offset' => 0,
                'status' => 'pending',
            ]);
        }

        $entity->forceFill(['planned_items' => (int) $entity->total_items])->save();
    }

    /** The source ran out before the count promised. Trust what we counted. */
    private function closeShort(StoreMigration $migration, MigrationEntity $entity): void
    {
        $planned = (int) $entity->planned_items;

        if ($planned >= (int) $entity->total_items) {
            return;
        }

        MigrationRunner::log(
            $migration->id,
            'info',
            $entity->entity_type,
            "Your store reported {$entity->total_items} {$entity->entity_type} records but listed {$planned} — "
                .'migrating what is actually there.',
        );

        $entity->forceFill(['total_items' => $planned])->save();
    }

    /**
     * Planning died for good. The run must not sit at "processing" for ever
     * waiting for a planner that is never coming back.
     */
    public function failed(?Throwable $e): void
    {
        MigrationRunner::log(
            $this->migrationId,
            'error',
            null,
            'Could not work out which records to migrate: '.($e?->getMessage() ?? 'unknown error')
                .'. Whatever was already planned will still be migrated.',
        );

        // Close planning off at whatever was pinned, so the batches that DO exist
        // can run and the migration can reach an end state. Better a run that
        // migrates most of the catalogue and says so than one that hangs.
        StoreMigration::where('id', $this->migrationId)
            ->whereNull('planned_at')
            ->update(['planned_at' => now()]);

        MigrationRunner::advance($this->migrationId);
    }
}
