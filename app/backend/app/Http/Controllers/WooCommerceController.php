<?php

namespace App\Http\Controllers;

use App\Migration\MigratorRegistry;
use App\Models\WoocommerceConnection;
use App\Services\MailSettings;
use App\Services\WooCommerceClient;
use App\Support\CurrentShop;
use App\Support\PlanAllowance;
use App\Support\PlanPool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WooCommerceController extends Controller
{
    /** Save credentials and test the connection. */
    public function connect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'store_url' => ['required', 'url'],
            'consumer_key' => ['required', 'string'],
            'consumer_secret' => ['required', 'string'],
        ]);

        $shop = CurrentShop::resolve($request);

        $connection = WoocommerceConnection::updateOrCreate(
            ['shop_id' => $shop->id],
            [
                'store_url' => rtrim($data['store_url'], '/'),
                'consumer_key' => $data['consumer_key'],
                'consumer_secret' => $data['consumer_secret'],
                'status' => 'disconnected',
            ],
        );

        return $this->runTest($connection);
    }

    /**
     * The stored connection, exactly as last recorded. No network call.
     *
     * The page used to load itself by running a live `test()`, which meant two
     * problems at once: opening it cost up to two minutes on a store that had
     * gone away, and a failed test answers 422 — so the client threw it away and
     * rendered a blank "connect your store" form at a merchant whose store was
     * connected. They could see neither their own store URL nor the fact that
     * their credentials had stopped working.
     */
    public function connection(Request $request): JsonResponse
    {
        $shop = CurrentShop::resolve($request);
        $connection = $shop->woocommerceConnection;

        if (! $connection) {
            return response()->json(null);
        }

        return response()->json($this->present($connection));
    }

    /** Re-test the existing connection. */
    public function test(Request $request): JsonResponse
    {
        $shop = CurrentShop::resolve($request);
        $connection = $shop->woocommerceConnection;

        abort_unless($connection, 404, 'No WooCommerce connection');

        return $this->runTest($connection);
    }

    /** Available counts per entity, for the preview/wizard. */
    public function preview(Request $request): JsonResponse
    {
        $shop = CurrentShop::resolve($request);
        $connection = $shop->woocommerceConnection;
        abort_unless($connection, 404, 'No WooCommerce connection');

        $registry = app(MigratorRegistry::class);
        $plan = $shop->currentPlan();

        // Why every count came back as zero, when that is what happened. A store
        // whose API keys have expired answers 401 to everything, and reporting
        // that as "0 products, 0 customers" sent merchants looking for a bug in
        // their catalogue instead of at their credentials.
        $sourceError = null;

        // Only entities that actually perform work. Variants, images and
        // attributes migrate INLINE with products, so counting them separately
        // double-counted the estimate and created pointless batch jobs.
        $display = ['product', 'category', 'customer', 'order'];
        $available = [];

        foreach ($display as $type) {
            try {
                $available[$type] = $registry->make($type)->count($connection);
            } catch (\Throwable $e) {
                $available[$type] = 0;
                $sourceError ??= $e->getMessage();
            }
        }

        // The shared record budget (see App\Support\PlanPool), spent in DEPENDENCY
        // order — the same order StartMigrationJob will spend it in. Claiming in
        // display order instead would hand the budget to a different entity here
        // than at run time, and the wizard would promise numbers the run then
        // contradicted.
        //
        // This assumes the merchant migrates everything. Selecting fewer entities
        // only ever frees budget up, so the preview under-promises rather than
        // over-promises, which is the safe direction to be wrong in.
        $pool = PlanPool::for($shop);

        // Snapshot BEFORE the claims below spend it. To a merchant "remaining"
        // means how much of their budget is still unspent right now — reporting
        // the state after a hypothetical full allocation would show them a number
        // that only exists because we just pretended to migrate everything.
        $poolSnapshot = $pool->isActive() ? $pool->toArray() : null;

        $verdicts = [];

        foreach ($registry->order($display) as $type) {
            $verdicts[$type] = PlanAllowance::forEntity($shop, $type, $available[$type], $pool);
        }

        // Ship the plan verdict with the count so the wizard can disable what the
        // merchant cannot migrate instead of failing after they start.
        $counts = array_map(
            fn (string $type) => ['type' => $type, 'count' => $available[$type]] + $verdicts[$type],
            $display,
        );

        return response()->json([
            'counts' => $counts,
            'plan' => $plan ? ['code' => $plan->code, 'name' => $plan->name] : null,
            // One shared allowance across products, customers and orders, or null
            // when this plan meters each entity on its own.
            'pool' => $poolSnapshot,
            // Prefills the "email me when it's done" field in the wizard.
            'shop_email' => $shop->email,
            // Whether that checkbox can deliver anything at all. Offering it on an
            // installation with no SMTP configured produces a merchant who waits
            // for an email that was never going to arrive.
            'email_delivery_configured' => MailSettings::canDeliver(),
            // The wizard refuses to start a second run while one is in flight, so
            // it needs to know before the merchant fills the whole form in.
            'active_migration_id' => MigrationController::activeMigrationId($shop),
            // Set when the counts could not be read at all — see above.
            'source_error' => $sourceError,
        ]);
    }

    private function runTest(WoocommerceConnection $connection): JsonResponse
    {
        $result = (new WooCommerceClient($connection))->test();

        $connection->update([
            'status' => $result['ok'] ? 'connected' : 'error',
            'wc_version' => $result['wc_version'],
            'wp_version' => $result['wp_version'],
            'last_tested_at' => now(),
        ]);

        // The 422 is what tells the client the credentials did not work; the body
        // still carries the connection AND the reason, so the page can say what
        // went wrong instead of silently rendering an empty form.
        return response()->json(
            $this->present($connection->refresh()) + ['error' => $result['error']],
            $result['ok'] ? 200 : 422,
        );
    }

    /** @return array<string, mixed> the connection, with the secret masked */
    private function present(WoocommerceConnection $connection): array
    {
        return [
            'store_url' => $connection->store_url,
            'consumer_key_masked' => $connection->consumer_key_masked,
            'status' => $connection->status,
            'wc_version' => $connection->wc_version,
            'wp_version' => $connection->wp_version,
            'last_tested_at' => $connection->last_tested_at,
        ];
    }
}
