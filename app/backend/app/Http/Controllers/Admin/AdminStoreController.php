<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BillingException;
use App\Http\Controllers\Controller;
use App\Migration\MigratorRegistry;
use App\Models\MigrationItem;
use App\Models\MigrationLog;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\Subscription;
use App\Services\ShopifyBilling;
use App\Support\BillingState;
use App\Support\QueueKeeper;
use App\Support\QueueMaintenance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminStoreController extends Controller
{
    /**
     * Queue health. Migrations only advance while a worker is consuming jobs,
     * so this is the first thing to check when progress looks stuck.
     */
    private function queueHealth(): array
    {
        // Driver-agnostic: on a Redis queue the `jobs` table still exists and is
        // permanently empty, so counting rows reported "0 jobs waiting" while
        // hundreds were backed up — the exact opposite of what this panel is for.
        $pending = QueueKeeper::pending();
        $failed = 0;
        $oldestSeconds = null;

        try {
            // Only the database driver can say HOW LONG the oldest job has been
            // waiting, which is what distinguishes a busy queue from a dead one.
            // On Redis there is no such column; `worker_stalled` then stays false
            // and the operator has the pending count and supervisor to go on.
            if (config('queue.default') === 'database' && \Illuminate\Support\Facades\Schema::hasTable('jobs')) {
                $oldest = \Illuminate\Support\Facades\DB::table('jobs')->min('available_at');
                if ($oldest) {
                    $oldestSeconds = max(0, time() - (int) $oldest);
                }
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('failed_jobs')) {
                $failed = \Illuminate\Support\Facades\DB::table('failed_jobs')->count();
            }
        } catch (\Throwable $e) {
            // A panel that cannot read the queue still has to render. Whatever
            // was gathered above stands; the rest stays at its default.
        }

        // A job waiting over ~2 minutes means nothing is consuming the queue.
        $stalled = $pending > 0 && $oldestSeconds !== null && $oldestSeconds > 120;

        return [
            'pending_jobs' => $pending,
            'failed_jobs' => $failed,
            'oldest_pending_seconds' => $oldestSeconds,
            'worker_stalled' => $stalled,
            // Whether the app can start its own worker on this host. When it
            // cannot (proc_open disabled, as on plenty of shared hosting) the
            // cron really is the only answer, and the panel has to say so rather
            // than offer a button that quietly does nothing.
            'can_self_start' => QueueKeeper::canSpawn(),
            'auto_worker_enabled' => (bool) config('queue.auto_worker', true),
            // How wide the self-starter is ALLOWED to run — the configured
            // ceiling, not what it would start right now. An operator looking at
            // a slow migration needs to know whether the app is pacing itself or
            // whether the host is the limit, and this is the number that decides.
            'auto_workers' => max(1, (int) config('queue.auto_workers', 2)),
        ];
    }

    /**
     * Start a worker now, from the panel.
     *
     * The banner used to tell the operator to SSH in and run a command. Most
     * people reading it are looking at a frozen migration on a phone.
     */
    public function runQueue(): JsonResponse
    {
        $started = QueueKeeper::ensureRunning();

        return response()->json([
            'started' => $started,
            'pending_jobs' => QueueKeeper::pending(),
            'can_self_start' => QueueKeeper::canSpawn(),
            'message' => match (true) {
                $started => 'Background worker(s) were started. Progress should resume within a few seconds.',
                ! config('queue.auto_worker', true) => 'The app is configured not to start its own workers '
                    .'(QUEUE_AUTO_WORKER=false), which is correct when supervisor or a worker cron is running. '
                    .'Check that it is up — see DEPLOYMENT.md §6.',
                ! QueueKeeper::canSpawn() => 'This server does not allow the app to start processes '
                    .'(proc_open is disabled), so a cron job is required. See DEPLOYMENT.md §6.',
                QueueKeeper::pending() === 0 => 'There is nothing queued — the workers have already drained it.',
                // Every worker slot being leased means the pool is already full,
                // most likely started by ordinary app traffic moments ago.
                // Reporting that as "try again later" read as the button having
                // failed, when in fact the thing the operator wanted had already
                // happened.
                default => 'Every worker slot is already in use — the pool was started within the last minute. '
                    .'Give it a few seconds and refresh.',
            },
        ]);
    }

    /** Put abandoned jobs back on the queue, then make sure something drains them. */
    public function retryFailedJobs(): JsonResponse
    {
        $retried = QueueMaintenance::retryFailedJobs();

        QueueKeeper::ensureRunning();

        return response()->json([
            'retried' => $retried,
            'failed_jobs' => $this->queueHealth()['failed_jobs'],
            'message' => $retried > 0
                ? "{$retried} job(s) were put back on the queue."
                : 'Nothing was re-queued. Any remaining failures have already been retried '
                    .'automatically and need looking at in Error logs.',
        ]);
    }

    /** Overview counters for the admin home. */
    public function overview(): JsonResponse
    {
        return response()->json([
            'queue' => $this->queueHealth(),
            'stores' => Shop::whereNull('uninstalled_at')->count(),
            'stores_uninstalled' => Shop::whereNotNull('uninstalled_at')->count(),
            'migrations' => StoreMigration::count(),
            'migrations_running' => StoreMigration::whereIn('status', StoreMigration::ACTIVE_STATES)->count(),
            'failed_items' => MigrationItem::where('status', 'failed')->count(),
            'open_tickets' => \App\Models\SupportTicket::whereIn('status', ['open', 'pending'])->count(),
            // Paying subscriptions only — free-plan rows are not subscriptions anyone pays for.
            'active_subscriptions' => Subscription::where('status', Subscription::ACTIVE)
                ->where(fn ($q) => $q->where('price', '>', 0)
                    ->orWhere(fn ($q) => $q->whereNull('price')->whereHas('plan', fn ($p) => $p->where('price', '>', 0))))
                ->count(),
            'frozen_subscriptions' => Subscription::where('status', Subscription::FROZEN)->count(),
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
    public function show(int $id, ShopifyBilling $billing): JsonResponse
    {
        $shop = Shop::findOrFail($id);

        // Reconcile with Shopify before showing billing (at most once a minute),
        // so the admin panel and the merchant's Pricing page read the same state.
        // Only for an installed store with a token; a failed lookup changes nothing.
        if ($shop->uninstalled_at === null && $shop->access_token) {
            $billing->syncIfStale($shop, 60, 'admin');
        }

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
            'entities' => $this->entityBreakdown($shop),
            'migrations' => $migrations,
            'billing' => BillingState::for($shop, forAdmin: true),
            'subscriptions' => BillingState::subscriptionRows($shop),
            'billing_events' => BillingState::history($shop, forAdmin: true, limit: 100),
            'recent_errors' => MigrationLog::whereIn('migration_id', StoreMigration::where('shop_id', $shop->id)->pluck('id'))
                ->where('level', 'error')->latest('created_at')->limit(20)->get(),
        ]);
    }

    /**
     * What this shop currently holds, per entity type.
     *
     * The mapping table is the thing a support reset acts on, so the operator
     * has to be able to see it before deciding — "reset products" means nothing
     * until you know whether that is 12 rows or 20,000.
     *
     * @return array<int, array<string, mixed>>
     */
    private function entityBreakdown(Shop $shop): array
    {
        $rows = MigrationItem::where('shop_id', $shop->id)
            ->selectRaw('entity_type, status, count(*) as aggregate')
            ->groupBy('entity_type', 'status')
            ->get();

        $byType = [];

        foreach ($rows as $row) {
            $type = $row->entity_type;

            $byType[$type] ??= ['type' => $type, 'total' => 0, 'success' => 0, 'failed' => 0, 'skipped' => 0, 'pending' => 0];
            $byType[$type]['total'] += (int) $row->aggregate;

            if (array_key_exists($row->status, $byType[$type])) {
                $byType[$type][$row->status] += (int) $row->aggregate;
            }
        }

        ksort($byType);

        return array_values($byType);
    }

    /**
     * Reset one entity type for one store, so it can be migrated again.
     *
     * Support asks for this when a merchant's data is wrong rather than missing:
     * a run that wrote the wrong vendor, a plan change, a catalogue re-imported
     * on the WooCommerce side. Without it the only answer was "we cannot", because
     * migration_items is unique per (shop, entity, source id) and a re-run
     * therefore skips or upserts every record it already knows about.
     *
     * TWO MODES, and the difference matters enough to be the whole point:
     *
     *   resync  Keeps the rows, clears the Shopify id, and sets attempts to 1.
     *           A re-run then treats each record as "we may already have created
     *           this and never found out" — which is exactly what
     *           AbstractMigrator::findExisting() is for — so it ADOPTS the object
     *           already in Shopify (by SKU, handle or email) and updates it.
     *           Nothing is duplicated.
     *
     *   forget  Deletes the rows outright. The shop is treated as never having
     *           migrated these records, so a re-run CREATES them again — which
     *           means duplicates unless the merchant has cleared them out of
     *           Shopify first. Occasionally that is what you want; it is never
     *           what you want by accident, so it is not the default.
     *
     * Both free the records from the current billing period's allowance, because
     * that is counted from successful rows.
     */
    public function resetEntity(Request $request, int $id): JsonResponse
    {
        $shop = Shop::findOrFail($id);

        $data = $request->validate([
            'entity_type' => ['required', 'string', 'max:40'],
            'mode' => ['sometimes', 'in:resync,forget'],
        ]);

        $entityType = $data['entity_type'];
        $mode = $data['mode'] ?? 'resync';

        abort_unless(
            app(MigratorRegistry::class)->has($entityType),
            422,
            "Unknown entity type: {$entityType}",
        );

        // A run in flight is writing to exactly these rows. Resetting underneath
        // it would have the migration re-create what it just made, and its
        // counters are derived from these rows so its progress would jump around.
        $active = StoreMigration::where('shop_id', $shop->id)
            ->whereIn('status', StoreMigration::ACTIVE_STATES)
            ->value('id');

        abort_if(
            $active !== null,
            409,
            "Migration #{$active} is still running for this store. Cancel or wait for it before resetting anything.",
        );

        $scope = fn () => MigrationItem::where('shop_id', $shop->id)->where('entity_type', $entityType);

        $affected = $mode === 'forget'
            ? $scope()->delete()
            : $scope()->update([
                'status' => 'pending',
                'shopify_id' => null,
                'error' => null,
                // NOT zero. Zero means "never attempted", and that is what sends a
                // re-run straight to creating a second copy. One means "we tried
                // and do not know how it ended", which is the state findExisting()
                // exists to resolve.
                'attempts' => 1,
                'updated_at' => now(),
            ]);

        Log::warning('Admin reset a store entity', [
            'shop_id' => $shop->id,
            'shop' => $shop->shopify_domain,
            'entity_type' => $entityType,
            'mode' => $mode,
            'rows' => $affected,
        ]);

        return response()->json([
            'entity_type' => $entityType,
            'mode' => $mode,
            'affected' => $affected,
            'entities' => $this->entityBreakdown($shop->refresh()),
            'message' => $affected === 0
                ? "This store has no {$entityType} records to reset."
                : ($mode === 'forget'
                    ? "{$affected} {$entityType} record(s) were forgotten. The next migration will CREATE them again — "
                        .'if they still exist in Shopify the merchant will end up with duplicates.'
                    : "{$affected} {$entityType} record(s) were reset. The next migration will re-migrate them and "
                        .'will re-use whatever it can already find in Shopify rather than duplicating it.'),
        ]);
    }

    /** Reconcile one store's billing with Shopify now. */
    public function syncBilling(int $id, ShopifyBilling $billing): JsonResponse
    {
        $shop = Shop::findOrFail($id);

        if ($shop->uninstalled_at !== null || ! $shop->access_token) {
            return response()->json([
                'error' => 'This store has uninstalled the app, so there is no access to its Shopify billing. Shopify cancelled its subscription on uninstall.',
                'billing' => BillingState::for($shop, forAdmin: true),
            ], 422);
        }

        $ok = $billing->sync($shop, 'admin');

        return response()->json([
            'synced' => $ok,
            'message' => $ok ? 'Billing reconciled with Shopify.' : 'Shopify could not be reached. Nothing was changed — see the billing events for the error.',
            'billing' => BillingState::for($shop->refresh(), forAdmin: true),
            'subscriptions' => BillingState::subscriptionRows($shop),
            'billing_events' => BillingState::history($shop, forAdmin: true, limit: 100),
        ], $ok ? 200 : 502);
    }

    /**
     * Cancel a store's subscription ON SHOPIFY (appSubscriptionCancel). Never a
     * local-only change: if Shopify can't confirm, nothing is recorded as cancelled.
     * Requires the store's domain typed back and a reason, both server-checked.
     */
    public function cancelBilling(Request $request, int $id, ShopifyBilling $billing): JsonResponse
    {
        $shop = Shop::findOrFail($id);

        $data = $request->validate([
            'confirm_domain' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'prorate' => ['required', 'boolean'],
        ]);

        if (! hash_equals($shop->shopify_domain, trim($data['confirm_domain']))) {
            return response()->json(['error' => 'The store domain you typed does not match. Nothing was cancelled.'], 422);
        }

        if ($shop->uninstalled_at !== null || ! $shop->access_token) {
            return response()->json(['error' => 'This store has uninstalled the app; Shopify already cancelled its subscription.'], 422);
        }

        try {
            $billing->cancel($shop, 'admin', (bool) $data['prorate'], $data['reason'].' (by '.($request->attributes->get('admin')?->email ?? 'admin').')');
        } catch (BillingException $e) {
            return response()->json($e->toResponseArray(), $e->httpStatus);
        }

        return response()->json([
            'message' => 'Subscription cancelled on Shopify.',
            'billing' => BillingState::for($shop->refresh(), forAdmin: true),
            'subscriptions' => BillingState::subscriptionRows($shop),
            'billing_events' => BillingState::history($shop, forAdmin: true, limit: 100),
        ]);
    }

    /** Compact billing summary for the stores list. */
    private static function billingSummary(Shop $s): array
    {
        $state = BillingState::for($s);

        return [
            'state' => $state['state'],
            'plan' => $state['plan'],
            'renews_at' => $state['renews_at'],
            'access_until' => $state['access_until'],
            'cancelled_at' => $state['cancelled_at'],
            'cancellation_reason' => $state['cancellation_reason'],
            'pending_change' => $state['pending_change'] !== null,
            'sync_failing' => $state['sync']['failing'],
        ];
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
            // The plan the shop is ACTUALLY on — Shop::currentPlan(), the same call
            // the merchant app makes. Reading only the active row showed "No
            // subscription" for a shop the app correctly placed on Starter.
            'plan' => $s->currentPlan()?->name,
            'plan_status' => $s->entitlement()['basis'] === 'free' ? 'free' : ($s->entitlement()['subscription']?->status ?? 'free'),
            'subscription' => self::billingSummary($s),
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
