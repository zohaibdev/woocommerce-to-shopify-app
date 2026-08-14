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
    /**
     * Wait before retrying a mutation Shopify soft-rate-limited. Orders refill in
     * small bursts, so a short pause and another attempt beats a long sleep.
     */
    private const SOFT_THROTTLE_BACKOFF = 60.0;

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

        // Some Shopify mutations — orderCreate above all — are rate limited
        // SEPARATELY from the GraphQL cost budget, and report it as an ordinary
        // userError instead of a 429 or a THROTTLED code. Left undetected it looks
        // like a permanent failure: a run would land ~5 orders, then mark every
        // remaining one failed. It is throttling, so treat it as throttling.
        if (self::looksRateLimited($body)) {
            throw new ShopifyThrottledException(self::SOFT_THROTTLE_BACKOFF);
        }

        $res->throw();

        return $body;
    }

    /**
     * A rate-limit message hidden in any mutation's userErrors.
     * Deliberately a whole-body scan: the client does not know which mutation
     * field it just called, and these messages are unmistakable.
     */
    private static function looksRateLimited(array $body): bool
    {
        if (empty($body['data'])) {
            return false;
        }

        $text = mb_strtolower((string) json_encode($body['data']));

        foreach (['too many attempts', 'exceeded rate limit', 'throttled'] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
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
