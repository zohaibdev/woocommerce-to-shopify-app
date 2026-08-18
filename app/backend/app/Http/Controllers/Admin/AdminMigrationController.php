<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MigrationItem;
use App\Models\MigrationLog;
use App\Models\StoreMigration;
use App\Support\MigrationDeveloperLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminMigrationController extends Controller
{
    /** All migrations across every store, filterable. */
    public function index(Request $request): JsonResponse
    {
        $query = StoreMigration::query()->with(['shop', 'entities']);

        if ($shopId = $request->query('shop_id')) {
            $query->where('shop_id', $shopId);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $rows = $query->latest()->paginate((int) $request->query('per_page', 20));

        return response()->json([
            'data' => $rows->getCollection()->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'shop' => $m->shop?->shopify_domain,
                'shop_id' => $m->shop_id,
                'status' => $m->status,
                'entities' => $m->selected_entities,
                'progress' => $m->entities->map(fn ($e) => [
                    'type' => $e->entity_type,
                    'processed' => $e->processed_items,
                    'total' => $e->total_items,
                    'successful' => $e->successful_items,
                    'failed' => $e->failed_items,
                    'percentage' => $e->percentage,
                ]),
                'started_at' => $m->started_at,
                'completed_at' => $m->completed_at,
                'created_at' => $m->created_at,
            ]),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'has_more' => $rows->hasMorePages(),
            ],
        ]);
    }

    /** One migration: progress, failed items and logs. */
    public function show(int $id): JsonResponse
    {
        $m = StoreMigration::with(['shop', 'entities'])->findOrFail($id);

        return response()->json([
            'id' => $m->id,
            'name' => $m->name,
            'shop' => $m->shop?->shopify_domain,
            'status' => $m->status,
            'options' => $m->options,
            'entities' => $m->entities->map(fn ($e) => [
                'type' => $e->entity_type,
                'total' => $e->total_items,
                'processed' => $e->processed_items,
                'successful' => $e->successful_items,
                'failed' => $e->failed_items,
                'percentage' => $e->percentage,
            ]),
            'failed_items' => MigrationItem::where('migration_id', $m->id)
                ->where('status', 'failed')->limit(200)->get(),
            'logs' => MigrationLog::where('migration_id', $m->id)
                ->latest('created_at')->limit(200)->get(),
            'started_at' => $m->started_at,
            'completed_at' => $m->completed_at,
        ]);
    }

    /**
     * Live developer view of one migration — what the system is doing, as
     * opposed to what happened to the merchant's data.
     *
     * Separate from show() on purpose: that endpoint feeds a support screen and
     * is shaped for a human reading about a merchant. This one is shaped for
     * whoever is trying to work out why a production run is crawling, and its
     * cost (a scan of the run's debug rows) should not be paid by every page
     * that happens to open a migration.
     */
    public function debug(int $id): JsonResponse
    {
        $migration = StoreMigration::with(['shop', 'entities'])->findOrFail($id);

        return response()->json(MigrationDeveloperLog::for($migration)->snapshot());
    }

    /**
     * The same thing as a text file, with the full log tail attached.
     *
     * Streamed: the log section is unbounded — a 50k-record run that failed
     * systemically has a line per record — and a debug tool that runs the server
     * out of memory is worse than no debug tool.
     */
    public function debugLog(int $id): StreamedResponse
    {
        $migration = StoreMigration::with(['shop', 'entities'])->findOrFail($id);
        $report = MigrationDeveloperLog::for($migration);

        return response()->streamDownload(
            fn () => $report->stream(),
            $report->filename(),
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    /** Error logs across all stores, filterable by store / migration. */
    public function errors(Request $request): JsonResponse
    {
        $query = MigrationLog::query()->where('level', 'error')->with('migration.shop');

        if ($migrationId = $request->query('migration_id')) {
            $query->where('migration_id', $migrationId);
        }
        if ($shopId = $request->query('shop_id')) {
            $query->whereIn('migration_id', StoreMigration::where('shop_id', $shopId)->pluck('id'));
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->query('to'));
        }

        $rows = $query->latest('created_at')->paginate((int) $request->query('per_page', 50));

        return response()->json([
            'data' => $rows->getCollection()->map(fn ($l) => [
                'id' => $l->id,
                'migration_id' => $l->migration_id,
                'shop' => $l->migration?->shop?->shopify_domain,
                'level' => $l->level,
                'entity_type' => $l->entity_type,
                'message' => $l->message,
                'context' => $l->context,
                'created_at' => $l->created_at,
            ]),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'has_more' => $rows->hasMorePages(),
            ],
        ]);
    }
}
