<?php

namespace App\Services;

use App\Models\WoocommerceConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the WooCommerce REST API v3.
 * Auth via consumer key/secret (Basic auth over HTTPS).
 */
class WooCommerceClient
{
    public function __construct(private readonly WoocommerceConnection $connection) {}

    private function client(): PendingRequest
    {
        $base = rtrim($this->connection->store_url, '/') . '/wp-json/wc/v3/';

        return Http::baseUrl($base)
            ->withBasicAuth($this->connection->consumer_key, $this->connection->consumer_secret)
            ->acceptJson()
            ->timeout(30)
            // Retry only transient failures (connection/5xx/429). Never retry a
            // 4xx like 401/403 — the credentials won't become valid on retry.
            ->retry(3, 1000, function ($exception) {
                $status = $exception instanceof \Illuminate\Http\Client\RequestException
                    ? $exception->response->status()
                    : 0;

                return $status === 0 || $status >= 500 || $status === 429;
            }, throw: false);
    }

    /** Test connection; returns [ok, wc_version, wp_version, error]. */
    public function test(): array
    {
        // system_status requires read scope; fall back to a lightweight products call.
        $res = $this->client()->get('system_status');
        if ($res->successful()) {
            $data = $res->json();
            return [
                'ok' => true,
                'wc_version' => data_get($data, 'environment.version'),
                'wp_version' => data_get($data, 'environment.wp_version'),
                'error' => null,
            ];
        }

        $probe = $this->client()->get('products', ['per_page' => 1]);
        return [
            'ok' => $probe->successful(),
            'wc_version' => null,
            'wp_version' => null,
            'error' => $probe->successful() ? null : "HTTP {$probe->status()}: {$probe->body()}",
        ];
    }

    /** Total count for a resource via the X-WP-Total header. */
    public function total(string $resource, array $query = []): int
    {
        $res = $this->client()->get($resource, array_merge(['per_page' => 1], $query));
        return (int) ($res->header('X-WP-Total') ?: 0);
    }

    /** Fetch a paginated list. */
    public function list(string $resource, int $page, int $perPage, array $query = []): array
    {
        $res = $this->client()->get($resource, array_merge([
            'page' => $page,
            'per_page' => $perPage,
        ], $query));

        $res->throw();

        return $res->json() ?? [];
    }
}
