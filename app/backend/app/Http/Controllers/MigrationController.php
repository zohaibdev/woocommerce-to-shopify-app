<?php

namespace App\Http\Controllers;

use App\Jobs\RetryFailedItemsJob;
use App\Jobs\StartMigrationJob;
use App\Migration\MigratorRegistry;
use App\Models\MigrationItem;
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
            ],
        ]);

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
