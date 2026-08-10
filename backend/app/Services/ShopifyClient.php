<?php

namespace App\Services;

use App\Models\Shop;
use App\Services\Exceptions\ShopifyThrottledException;
use Illuminate\Support\Facades\Http;

/**
 * Shopify Admin GraphQL API client with throttle handling.
 * Always use GraphQL; avoid deprecated REST endpoints.
 */
class ShopifyClient
{
    private string $apiVersion;

    public function __construct(private readonly Shop $shop)
    {
        $this->apiVersion = config('services.shopify.api_version', '2024-10');
    }

    /**
     * Execute a GraphQL query/mutation.
     *
     * @throws ShopifyThrottledException when the call is throttled (caller should release the job).
     */
    public function graphql(string $query, array $variables = []): array
    {
        $url = "https://{$this->shop->shopify_domain}/admin/api/{$this->apiVersion}/graphql.json";

        // Offline tokens now expire — refresh (or migrate a legacy one) first.
        app(ShopifyTokenService::class)->ensureFresh($this->shop);

        $res = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->shop->access_token,
            'Content-Type' => 'application/json',
        ])->timeout(30)->post($url, [
            'query' => $query,
            // Cast to object so an empty variables set encodes as {} not [];
            // Shopify rejects a JSON array here with "Invalid variables parameter".
            'variables' => (object) $variables,
        ]);

        if ($res->status() === 429) {
            $retryAfter = (float) ($res->header('Retry-After') ?: 2);
            throw new ShopifyThrottledException($retryAfter);
        }

        $body = $res->json() ?? [];

        // GraphQL-level throttling.
        $errors = self::normalizeErrors($body['errors'] ?? []);
        foreach ($errors as $err) {
            if (($err['extensions']['code'] ?? null) === 'THROTTLED') {
                $status = data_get($body, 'extensions.cost.throttleStatus');
                $available = (float) data_get($status, 'currentlyAvailable', 0);
                $restoreRate = (float) data_get($status, 'restoreRate', 50);
                $needed = (float) data_get($body, 'extensions.cost.requestedQueryCost', 100);
                $wait = $restoreRate > 0 ? max(1, ceil(($needed - $available) / $restoreRate)) : 2;
                throw new ShopifyThrottledException((float) $wait);
            }
        }

        $res->throw();

        return $body;
    }

    /** Extract userErrors from a mutation payload, if any. */
    public function userErrors(array $body, string $mutationField): array
    {
        return data_get($body, "data.{$mutationField}.userErrors", []);
    }

    /** Top-level GraphQL errors (e.g. ACCESS_DENIED for a missing scope). */
    public function topLevelErrors(array $body): array
    {
        return self::normalizeErrors($body['errors'] ?? []);
    }

    /**
     * Shopify returns `errors` either as a list of error objects OR as a plain
     * string (e.g. {"errors":"[API] This action requires merchant approval..."}).
     * Normalise both shapes to a list of ['message' => string, ...] entries so
     * callers can always iterate safely.
     */
    public static function normalizeErrors(mixed $errors): array
    {
        if (is_string($errors)) {
            return $errors === '' ? [] : [['message' => $errors]];
        }

        if (! is_array($errors)) {
            return [];
        }

        // An associative single error object -> wrap it in a list.
        if (isset($errors['message']) || isset($errors['extensions'])) {
            return [$errors];
        }

        return array_values(array_filter($errors, fn ($e) => is_array($e)));
    }
}
