<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MigrationItem;
use App\Models\MigrationLog;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminStoreController extends Controller
{
    /** Overview counters for the admin home. */
    public function overview(): JsonResponse
    {
        return response()->json([
            'stores' => Shop::whereNull('uninstalled_at')->count(),
            'stores_uninstalled' => Shop::whereNotNull('uninstalled_at')->count(),
            'migrations' => StoreMigration::count(),
            'migrations_running' => StoreMigration::whereIn('status', StoreMigration::ACTIVE_STATES)->count(),
            'failed_items' => MigrationItem::where('status', 'failed')->count(),
            'open_tickets' => \App\Models\SupportTicket::whereIn('status', ['open', 'pending'])->count(),
            'active_subscriptions' => Subscription::where('status', 'active')->count(),
        ]);
    }

    /** All installed stores with plan + migration status. */
    public function index(Request $request): JsonResponse
    {
        $query = Shop::query()->with(['activeSubscription.plan', 'woocommerceConnection']);

        if ($search = $request->query('search')) {
            $query->where('shopify_domain', 'like', "%{$search}%");
        }
        if ($request->query('status') === 'installed') {
            $query->whereNull('uninstalled_at');
        } elseif ($request->query('status') === 'uninstalled') {
            $query->whereNotNull('uninstalled_at');
        }

        $shops = $query->latest()->paginate((int) $request->query('per_page', 20));

        return response()->json([
            'data' => $shops->getCollection()->map(fn (Shop $s) => $this->present($s)),
            'meta' => $this->meta($shops),
        ]);
    }

    /** Full detail for one store: plan, migrations, recent errors. */
    public function show(int $id): JsonResponse
    {
        $shop = Shop::with(['activeSubscription.plan', 'woocommerceConnection'])->findOrFail($id);

        $migrations = StoreMigration::where('shop_id', $shop->id)
            ->with('entities')->latest()->limit(20)->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'status' => $m->status,
                'entities' => $m->selected_entities,
                'created_at' => $m->created_at,
                'progress' => $m->entities->map(fn ($e) => [
                    'type' => $e->entity_type,
                    'processed' => $e->processed_items,
                    'total' => $e->total_items,
                    'failed' => $e->failed_items,
                ]),
            ]);

        return response()->json([
            'store' => $this->present($shop),
            'migrations' => $migrations,
            'subscriptions' => $shop->subscriptions()->with('plan')->latest()->get()
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'plan' => $s->plan?->name,
                    'status' => $s->status,
                    'started_at' => $s->started_at,
                    'ends_at' => $s->ends_at,
                ]),
            'recent_errors' => MigrationLog::whereIn('migration_id', StoreMigration::where('shop_id', $shop->id)->pluck('id'))
                ->where('level', 'error')->latest('created_at')->limit(20)->get(),
        ]);
    }

    private function present(Shop $s): array
    {
        $latest = StoreMigration::where('shop_id', $s->id)->latest()->first();

        return [
            'id' => $s->id,
            'shopify_domain' => $s->shopify_domain,
            'name' => $s->name,
            'installed' => $s->uninstalled_at === null,
            'installed_at' => $s->installed_at,
            'uninstalled_at' => $s->uninstalled_at,
            'scopes' => $s->scopes,
            'woocommerce' => $s->woocommerceConnection ? [
                'store_url' => $s->woocommerceConnection->store_url,
                'status' => $s->woocommerceConnection->status,
            ] : null,
            'plan' => $s->activeSubscription?->plan?->name,
            'plan_status' => $s->activeSubscription?->status,
            'migrations_count' => StoreMigration::where('shop_id', $s->id)->count(),
            'last_migration' => $latest ? [
                'id' => $latest->id,
                'status' => $latest->status,
                'created_at' => $latest->created_at,
            ] : null,
        ];
    }

    private function meta($p): array
    {
        return [
            'current_page' => $p->currentPage(),
            'last_page' => $p->lastPage(),
            'per_page' => $p->perPage(),
            'total' => $p->total(),
            'has_more' => $p->hasMorePages(),
        ];
    }
}
