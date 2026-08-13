<?php

namespace App\Http\Controllers;

use App\Jobs\RetryFailedItemsJob;
use App\Jobs\StartMigrationJob;
use App\Migration\MigrationRunner;
use App\Migration\MigratorRegistry;
use App\Models\MigrationEntity;
use App\Models\MigrationItem;
use App\Models\MigrationLog;
use App\Models\StoreMigration;
use App\Support\CurrentShop;
use App\Support\PlanAllowance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MigrationController extends Controller
{
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
            ],
        ]);
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

        // Reject unknown entities and enforce the variation->attribute dependency.
        $entities = array_values(array_filter($data['entities'], fn ($e) => $registry->has($e)));
        if (in_array('variation', $entities, true) && ! in_array('attribute', $entities, true)) {
            $entities[] = 'attribute';
        }
        abort_if(empty($entities), 422, 'No valid entities selected');

        $shop = CurrentShop::resolve($request);
        $connection = $shop->woocommerceConnection;
        abort_unless($connection && $connection->status === 'connected', 422, 'WooCommerce not connected');

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

    public function items(Request $request, int $id): JsonResponse
    {
        $migration = $this->find($request, $id);

        $query = MigrationItem::where('migration_id', $migration->id);
        if ($request->filled('entity')) {
            $query->where('entity_type', $request->string('entity'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json(['data' => $query->limit(500)->get()]);
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

        $hasFailures = MigrationItem::where('migration_id', $migration->id)
            ->where('status', 'failed')
            ->exists();

        abort_unless($hasFailures, 422, 'There are no failed items to retry.');

        // Flip to processing HERE, not inside the job. The response is what the
        // page reads, and a terminal status told it to stop polling — so the retry
        // looked like it did nothing until the queue happened to pick the job up.
        $migration->update(['status' => 'processing', 'completed_at' => null]);

        MigrationRunner::log($migration->id, 'info', null, 'Retrying failed items…');

        RetryFailedItemsJob::dispatch($migration->id);

        return response()->json($this->present($migration->fresh()->load('entities')));
    }

    /** Download a plain-text report of the migration (summary + failed items + logs). */
    public function download(Request $request, int $id)
    {
        $migration = $this->find($request, $id);
        $migration->load('entities', 'logs');

        $lines = [];
        $lines[] = 'Migration #' . $migration->id . ($migration->name ? ' — ' . $migration->name : '');
        $lines[] = 'Status: ' . $migration->status;
        $lines[] = 'Started: ' . ($migration->started_at ?? '-') . '   Completed: ' . ($migration->completed_at ?? '-');
        $lines[] = str_repeat('=', 60);
        $lines[] = 'SUMMARY';
        foreach ($migration->entities as $e) {
            $lines[] = sprintf('  %-10s  %d/%d   ok=%d  failed=%d',
                $e->entity_type, $e->processed_items, $e->total_items, $e->successful_items, $e->failed_items);
        }

        $failed = MigrationItem::where('migration_id', $migration->id)->where('status', 'failed')->get();
        $lines[] = '';
        $lines[] = 'FAILED ITEMS (' . $failed->count() . ')';
        foreach ($failed as $f) {
            $lines[] = sprintf('  [%s] woo #%d: %s', $f->entity_type, $f->woocommerce_id, $f->error);
        }

        $lines[] = '';
        $lines[] = 'LOGS';
        foreach ($migration->logs as $log) {
            $lines[] = sprintf('  %s [%s] %s %s', $log->created_at, strtoupper($log->level), $log->entity_type, $log->message);
        }

        $content = implode("\n", $lines) . "\n";
        $filename = "migration-{$migration->id}-log.txt";

        return response($content, 200, [
            'Content-Type' => 'text/plain',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
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
        ];
    }
}
