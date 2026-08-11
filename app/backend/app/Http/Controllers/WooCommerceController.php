<?php

namespace App\Http\Controllers;

use App\Migration\MigratorRegistry;
use App\Models\WoocommerceConnection;
use App\Services\WooCommerceClient;
use App\Support\CurrentShop;
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
        $counts = [];
        foreach (['product', 'category', 'attribute', 'variation', 'media', 'customer', 'order'] as $type) {
            try {
                $counts[] = ['type' => $type, 'count' => $registry->make($type)->count($connection)];
            } catch (\Throwable $e) {
                $counts[] = ['type' => $type, 'count' => 0];
            }
        }

        return response()->json(['counts' => $counts]);
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

        return response()->json([
            'store_url' => $connection->store_url,
            'consumer_key_masked' => $connection->consumer_key_masked,
            'status' => $connection->status,
            'wc_version' => $connection->wc_version,
            'wp_version' => $connection->wp_version,
            'last_tested_at' => $connection->last_tested_at,
        ], $result['ok'] ? 200 : 422);
    }
}
