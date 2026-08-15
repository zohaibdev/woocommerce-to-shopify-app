<?php

namespace App\Http\Controllers;

use App\Jobs\RetryFailedItemsJob;
use App\Jobs\StartMigrationJob;
use App\Migration\MigrationRunner;
use App\Migration\MigratorRegistry;
use App\Models\MigrationBatch;
use App\Models\MigrationEntity;
use App\Models\MigrationItem;
use App\Models\MigrationLog;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Support\CurrentShop;
use App\Support\PlanAllowance;
use App\Support\SourceHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MigrationController extends Controller
{
    /**
     * Entities that drag another one in with them.
     *
     * These are the dependencies whose ABSENCE breaks the run rather than merely
     * degrading it: a review has no product to attach to, an article has no blog
     * to sit in, a variation has no attribute to build options from.
     */
    private const IMPLIED_ENTITIES = [
        'variation' => 'attribute',
        'review' => 'product',
        'article' => 'blog',
    ];

    public function index(Request $request): JsonResponse
    {
        $shop = CurrentShop::resolve($request);

        $perPage = min(50, max(5, (int) $request->query('per_page', 10)));

        $migrations = $shop->migrations()
            ->with('entities')
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'data' => $migrations->getCollection()->map(fn ($m) => $this->present($m)),
            'meta' => [
                'current_page' => $migrations->currentPage(),
                'last_page' => $migrations->lastPage(),
                'per_page' => $migrations->perPage(),
                'total' => $migrations->total(),
                'has_more' => $migrations->hasMorePages(),
                // Lets the list hide "New migration" without having to guess from
                // whichever page of results it happens to be showing.
                'active_migration_id' => self::activeMigrationId($shop),
            ],
        ]);
    }

    /** The shop's in-flight run, if it has one. At most one may exist (see store()). */
    public static function activeMigrationId(Shop $shop): ?int
    {
        return StoreMigration::where('shop_id', $shop->id)
            ->whereIn('status', StoreMigration::ACTIVE_STATES)
            ->orderByDesc('id')
            ->value('id');
    }

    public function store(Request $request): JsonResponse
    {
        $registry = app(MigratorRegistry::class);

        $data = $request->validate([
            'entities' => ['required', 'array', 'min:1'],
            'entities.*' => ['string'],
            'custom_tags' => ['sometimes', 'array'],
            'custom_tags.*' => ['string'],
            'default_vendor' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'weight_unit' => ['sometimes', 'in:KILOGRAMS,GRAMS,POUNDS,OUNCES'],
            // Shopify downloads every image itself and productSet waits for it, so
            // image size is the single biggest lever the merchant has over speed.
            'image_size' => ['sometimes', 'in:standard,large,full'],
            // Migrations can run for hours; the merchant can ask to be told when
            // this one is done instead of watching the page.
            'notify_email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ]);

        // Normalise custom tags (accept comma-separated strings too).
        $customTags = collect($data['custom_tags'] ?? [])
            ->flatMap(fn ($t) => explode(',', $t))
            ->map(fn ($t) => trim($t))
            ->filter()
            ->unique()
            ->values()
            ->all();

        // Reject unknown entities, then pull in anything the selection cannot work
        // without. A dependency is only honoured by the registry's ordering when
        // it is IN the selection, so picking reviews without products would have
        // failed every single review with "the product was not migrated" — a run
        // that is guaranteed to fail is not one worth starting.
        $entities = array_values(array_filter($data['entities'], fn ($e) => $registry->has($e)));

        foreach (self::IMPLIED_ENTITIES as $selected => $required) {
            if (in_array($selected, $entities, true) && ! in_array($required, $entities, true)) {
                $entities[] = $required;
            }
        }

        abort_if(empty($entities), 422, 'No valid entities selected');

        $shop = CurrentShop::resolve($request);
        $connection = $shop->woocommerceConnection;
        abort_unless($connection && $connection->status === 'connected', 422, 'WooCommerce not connected');

        // One run at a time per shop.
        //
        // migration_items is unique per (shop, entity, source id), so a second
        // concurrent run does not duplicate anything — it CLAIMS the first run's
        // mapping rows by rewriting their migration_id. Counters are derived from
        // those rows, so both runs then report numbers that are wrong in opposite
        // directions, and the merchant watches one migration's progress travel
        // backwards. On top of that both runs write the same products to Shopify,
        // doubling the API cost of the very thing being rate limited.
        if ($active = self::activeMigrationId($shop)) {
            abort(409, "Migration #{$active} is still running. Wait for it to finish, or cancel it, before starting another.");
        }

        // The wizard disables these, so getting here means a stale page or a direct
        // API call. Refuse plainly instead of starting a run that migrates nothing.
        $locked = PlanAllowance::lockedAmong($shop, $entities);
        abort_if(
            ! empty($locked),
            422,
            'Your '.($shop->currentPlan()?->name ?? 'current').' plan does not include: '
                .implode(', ', $locked).'. Upgrade your plan to migrate them.',
        );

        $migration = StoreMigration::create([
            'shop_id' => $shop->id,
            'name' => trim((string) $request->input('name', '')) ?: null,
            'woocommerce_connection_id' => $connection->id,
            'status' => 'pending',
            'selected_entities' => $entities,
            'options' => [
                'custom_tags' => $customTags,
                'default_vendor' => trim((string) $request->input('default_vendor', '')),
                // WooCommerce doesn't expose its weight unit, so the merchant picks it.
                'weight_unit' => $request->input('weight_unit')
                    ?: config('migration.weight_unit', 'KILOGRAMS'),
                'image_size' => $request->input('image_size')
                    ?: config('migration.image_size', 'standard'),
                'notify_email' => trim((string) $request->input('notify_email', '')) ?: null,
            ],
        ]);

        // Write the entity rows up front so the details page has something to draw
        // immediately. Planning has to make a WooCommerce count call per entity, and
        // until it finished the merchant saw an empty card and assumed it had hung.
        foreach ($registry->order($entities) as $entityType) {
            MigrationEntity::firstOrCreate(
                ['migration_id' => $migration->id, 'entity_type' => $entityType],
                ['status' => 'pending', 'total_items' => 0],
            );
        }

        // Enqueue — migrations NEVER run in the request lifecycle.
        StartMigrationJob::dispatch($migration->id);

        return response()->json($this->present($migration->load('entities')), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $migration = $this->find($request, $id);

        return response()->json($this->present($migration->load('entities')));
    }

    /**
     * Items for a migration.
     *
     * Paginated rather than capped: a large run can fail thousands of records,
     * and a bare `limit(500)` with no ordering meant the merchant saw an
     * arbitrary, unrepeatable 500 of them and had no way to reach the rest.
     */
    public function items(Request $request, int $id): JsonResponse
    {
        $migration = $this->find($request, $id);

        $perPage = min(200, max(10, (int) $request->query('per_page', 100)));

        $query = MigrationItem::where('migration_id', $migration->id)->orderBy('id');

        if ($request->filled('entity')) {
            $query->where('entity_type', $request->string('entity'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $items = $query->paginate($perPage);

        return response()->json([
            'data' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'has_more' => $items->hasMorePages(),
            ],
        ]);
    }

    /**
     * Live activity log. SPECIFICATION.md §7 listed this endpoint but it was never
     * built, so a merchant watching a run had no way to see what was happening —
     * the log only existed inside the downloadable report.
     */
    public function logs(Request $request, int $id): JsonResponse
    {
        $migration = $this->find($request, $id);

        $limit = min(200, max(10, (int) $request->query('limit', 100)));

        $logs = MigrationLog::where('migration_id', $migration->id)
            ->when($request->filled('after'), fn ($q) => $q->where('id', '>', (int) $request->query('after')))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (MigrationLog $l) => [
                'id' => $l->id,
                'level' => $l->level,
                'entity_type' => $l->entity_type,
                'message' => $l->message,
                'created_at' => $l->created_at,
            ]);

        return response()->json(['data' => $logs->values()]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $migration = $this->find($request, $id);

        if (in_array($migration->status, StoreMigration::ACTIVE_STATES, true)) {
            $migration->update(['status' => 'cancelled']);
        }

        return response()->json($this->present($migration->fresh()->load('entities')));
    }

    public function retryFailed(Request $request, int $id): JsonResponse
    {
        $migration = $this->find($request, $id);

        // Failed BATCHES count too. A batch fails as a whole when its WooCommerce
        // page could not be read at all, and then its records have no rows in
        // migration_items — so gating on failed items alone refused to retry the
        // one failure the merchant had no other way to recover from.
        $hasFailures = MigrationItem::where('migration_id', $migration->id)
            ->where('status', 'failed')
            ->exists()
            || self::failedBatchCount($migration) > 0;

        abort_unless($hasFailures, 422, 'There are no failed items to retry.');

        // Flip to processing HERE, not inside the job. The response is what the
        // page reads, and a terminal status told it to stop polling — so the retry
        // looked like it did nothing until the queue happened to pick the job up.
        $migration->update(['status' => 'processing', 'completed_at' => null]);

        MigrationRunner::log($migration->id, 'info', null, 'Retrying failed items…');

        RetryFailedItemsJob::dispatch($migration->id);

        return response()->json($this->present($migration->fresh()->load('entities')));
    }

    /**
     * Download a plain-text report of the migration (summary + failed items + logs).
     *
     * Streamed and chunked. A big run produces tens of thousands of failed items
     * and log lines, and building that report as one string in memory was a
     * guaranteed out-of-memory (or timeout) for exactly the merchants who most
     * need to read it.
     */
    public function download(Request $request, int $id)
    {
        $migration = $this->find($request, $id);
        $migration->load('entities');

        $filename = "migration-{$migration->id}-log.txt";

        return response()->streamDownload(function () use ($migration) {
            $line = function (string $text = '') {
                echo $text."\n";
            };

            $line('Migration #'.$migration->id.($migration->name ? ' — '.$migration->name : ''));
            $line('Status: '.$migration->status);
            $line('Started: '.($migration->started_at ?? '-').'   Completed: '.($migration->completed_at ?? '-'));
            $line(str_repeat('=', 60));
            $line('SUMMARY');

            foreach ($migration->entities as $e) {
                $line(sprintf('  %-10s  %d/%d   ok=%d  failed=%d',
                    $e->entity_type, $e->processed_items, $e->total_items, $e->successful_items, $e->failed_items));
            }

            $failedQuery = MigrationItem::where('migration_id', $migration->id)->where('status', 'failed');

            $line();
            $line('FAILED ITEMS ('.$failedQuery->count().')');

            $failedQuery->orderBy('id')->chunk(1000, function ($rows) use ($line) {
                foreach ($rows as $f) {
                    $line(sprintf('  [%s] woo #%d: %s', $f->entity_type, $f->woocommerce_id, $f->error));
                }
            });

            // Chunks that never produced items at all. Listing them by the range
            // they covered is the only record the merchant has of WHICH part of
            // their catalogue did not get read.
            $failedBatches = MigrationBatch::where('migration_id', $migration->id)
                ->where('status', 'failed')
                ->orderBy('id')
                ->get();

            if ($failedBatches->isNotEmpty()) {
                $line();
                $line('UNREAD BATCHES ('.$failedBatches->count().')');
                $line('  These chunks could not be read from WooCommerce, so their records are');
                $line('  NOT in the failed-items list above. "Retry failed items" runs them again.');

                foreach ($failedBatches as $b) {
                    $from = (($b->page - 1) * $b->per_page) + 1;
                    $to = $b->page * $b->per_page;

                    $line(sprintf('  [%s] batch %d (records ~%d-%d, resumed at %d): %s',
                        $b->entity_type, $b->batch_number, $from, $to, $b->processed_offset, $b->error));
                }
            }

            $line();
            $line('LOGS');

            MigrationLog::where('migration_id', $migration->id)
                ->orderBy('id')
                ->chunk(1000, function ($rows) use ($line) {
                    foreach ($rows as $log) {
                        $line(sprintf('  %s [%s] %s %s',
                            $log->created_at, strtoupper($log->level), $log->entity_type, $log->message));
                    }
                });
        }, $filename, [
            'Content-Type' => 'text/plain',
        ]);
    }

    /** Batches whose records were never read from WooCommerce at all. */
    private static function failedBatchCount(StoreMigration $migration): int
    {
        return MigrationBatch::where('migration_id', $migration->id)
            ->where('status', 'failed')
            ->count();
    }

    private function find(Request $request, int $id): StoreMigration
    {
        $shop = CurrentShop::resolve($request);

        return StoreMigration::where('shop_id', $shop->id)->findOrFail($id);
    }

    private function present(StoreMigration $m): array
    {
        return [
            'id' => $m->id,
            'name' => $m->name,
            'status' => $m->status,
            'selected_entities' => $m->selected_entities,
            'entities' => $m->entities->map(fn ($e) => [
                'type' => $e->entity_type,
                'total' => $e->total_items,
                'processed' => $e->processed_items,
                'successful' => $e->successful_items,
                'failed' => $e->failed_items,
                'percentage' => $e->percentage,
            ])->values(),
            'started_at' => $m->started_at,
            'completed_at' => $m->completed_at,
            'created_at' => $m->created_at,
            // Chunks whose records were never read from WooCommerce. They produce
            // no failed ITEMS, so without this the page had no way to know they
            // existed and offered the merchant no retry.
            'failed_batches' => self::failedBatchCount($m),
            // How the merchant's own WooCommerce host is holding up. Only while
            // the run is live — after it ends the number describes history, not
            // something they can act on.
            'source_health' => in_array($m->status, StoreMigration::ACTIVE_STATES, true)
                ? SourceHealth::snapshot($m->id)
                : null,
        ];
    }
}
