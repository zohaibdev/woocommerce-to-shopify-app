<?php

namespace App\Services;

use App\Models\Shop;
use App\Services\Exceptions\ShopifyThrottledException;
use App\Support\MigrationDiagnostics;
use Illuminate\Support\Facades\Http;

/**
 * Shopify Admin GraphQL API client with throttle handling.
 * Always use GraphQL; avoid deprecated REST endpoints.
 */
class ShopifyClient
{
    /**
     * FIRST wait after a mutation Shopify soft-rate-limited — deliberately short.
     *
     * Shopify sends no Retry-After with these, so this is a guess, and it is
     * flagged as one (`measured: false`). ProcessBatchJob owns the escalation: it
     * probes at this interval and backs off towards a minute only once the probe
     * has been refused. This constant used to BE a flat sixty seconds, which on
     * an order migration meant roughly five orders of work followed by a minute
     * of a worker holding a concurrency slot and doing nothing — whether or not
     * the window had already reopened.
     */
    private const SOFT_THROTTLE_BACKOFF = 5.0;

    /**
     * Keep this fraction of the shop's point budget in reserve. Hitting zero
     * costs a full THROTTLED round trip plus a re-queued job; easing off as the
     * bucket drains costs milliseconds. The reserve also leaves room for the
     * other workers running in parallel on the same shop.
     */
    private const BUCKET_RESERVE = 0.25;

    /** Never pause longer than this in a single call — the job has a time budget. */
    private const MAX_PACE_SECONDS = 5.0;

    /**
     * productSet runs synchronously: Shopify downloads every image on the product
     * before the mutation returns. A gallery of twenty photos pulled from a slow
     * WordPress host does not fit in 30 seconds, and the cURL timeout was being
     * recorded as a permanent item failure — for a product Shopify may well have
     * created, which the next run then created again.
     *
     * Must stay under the jobs' $timeout (300s) with room for the retries below.
     */
    private const TIMEOUT_SECONDS = 90;

    /**
     * Shopify has 5xx days like everyone else, and a dropped connection is not a
     * verdict on the merchant's data. Without this a transient blip permanently
     * failed whichever product happened to be in flight.
     */
    private const TRANSIENT_TRIES = 3;

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

        // Timed so the developer log can say how much of a slow run is Shopify
        // and how much is the merchant's own WooCommerce host — the single most
        // common wrong guess about why a migration is crawling.
        $res = MigrationDiagnostics::time('shopify', fn () => Http::withHeaders([
            'X-Shopify-Access-Token' => $this->shop->access_token,
            'Content-Type' => 'application/json',
        ])
            ->timeout(self::TIMEOUT_SECONDS)
            // Retry only what a retry can fix: a dropped connection or a 5xx.
            // 429 is deliberately excluded — it is handled below with Shopify's
            // own Retry-After, which is a far better number than a fixed sleep.
            ->retry(self::TRANSIENT_TRIES, 1000, function ($exception) {
                $status = $exception instanceof \Illuminate\Http\Client\RequestException
                    ? $exception->response->status()
                    : 0;

                return $status === 0 || $status >= 500;
            }, throw: false)
            ->post($url, [
                'query' => $query,
                // Cast to object so an empty variables set encodes as {} not [];
                // Shopify rejects a JSON array here with "Invalid variables parameter".
                'variables' => (object) $variables,
            ]));

        if ($res->status() === 429) {
            $retryAfter = (float) ($res->header('Retry-After') ?: 2);
            MigrationDiagnostics::bump('shopify_429');

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
                MigrationDiagnostics::bump('shopify_cost_throttled');

                throw new ShopifyThrottledException((float) $wait);
            }
        }

        // Some Shopify mutations — orderCreate above all — are rate limited
        // SEPARATELY from the GraphQL cost budget, and report it as an ordinary
        // userError instead of a 429 or a THROTTLED code. Left undetected it looks
        // like a permanent failure: a run would land ~5 orders, then mark every
        // remaining one failed. It is throttling, so treat it as throttling.
        if (self::looksRateLimited($body)) {
            MigrationDiagnostics::bump('shopify_soft_throttled');

            throw new ShopifyThrottledException(self::SOFT_THROTTLE_BACKOFF, measured: false);
        }

        $res->throw();

        // Learn this shop's real budget and ease off before we exhaust it.
        $this->pace($body);

        return $body;
    }

    /**
     * Adaptive pacing from the shop's own throttle status.
     *
     * Every response reports the bucket size, what is left in it, and how fast it
     * refills — and those numbers differ 20x between a Standard store and an
     * Enterprise one. Reacting to them instead of assuming a fixed rate means a
     * Plus merchant runs at Plus speed automatically, while a Standard merchant
     * is slowed just enough to never get throttled at all.
     *
     * Waiting here rather than being rejected is the cheaper trade: a THROTTLED
     * error costs a wasted round trip and a re-queued job, this costs a sleep.
     */
    private function pace(array $body): void
    {
        $status = data_get($body, 'extensions.cost.throttleStatus');

        if (! $status) {
            return;
        }

        $available = (float) data_get($status, 'currentlyAvailable', 0);
        $bucket = (float) data_get($status, 'maximumAvailable', 0);
        $restoreRate = (float) data_get($status, 'restoreRate', 0);

        if ($bucket <= 0 || $restoreRate <= 0) {
            return;
        }

        // The migration engine reads these to decide how many workers to run.
        app(ShopifyPlan::class)->remember($this->shop, $bucket, $restoreRate);

        $floor = $bucket * self::BUCKET_RESERVE;

        if ($available >= $floor) {
            return;
        }

        $wait = min(($floor - $available) / $restoreRate, self::MAX_PACE_SECONDS);

        // Recorded separately from the call itself: this is time the app chose to
        // spend, and on a shop with a small bucket it can quietly become most of
        // the run. Reading it as Shopify being slow would send an operator to fix
        // the wrong thing.
        MigrationDiagnostics::bump('pace_waits');
        MigrationDiagnostics::add('pace_seconds', $wait);

        usleep((int) round($wait * 1_000_000));
    }

    /**
     * A rate-limit message hidden in any mutation's userErrors.
     *
     * Scoped to userError MESSAGES rather than the whole response body. The scan
     * used to run over everything under `data`, which meant a collection called
     * "Throttled" — or any merchant string Shopify happened to echo back — put
     * the batch to sleep for a minute and re-queued it, over and over, because
     * nothing about that condition ever changes.
     */
    private static function looksRateLimited(array $body): bool
    {
        foreach (self::userErrorMessages($body) as $message) {
            foreach (['too many attempts', 'exceeded rate limit', 'throttled', 'rate limit'] as $needle) {
                if (str_contains($message, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Every userError message in the response, lower-cased.
     *
     * The client does not know which mutation field it just called, so it walks
     * one level of `data` and collects the `userErrors` of whatever it finds.
     *
     * @return array<int, string>
     */
    private static function userErrorMessages(array $body): array
    {
        $messages = [];

        foreach ((array) ($body['data'] ?? []) as $payload) {
            if (! is_array($payload)) {
                continue;
            }

            foreach ((array) ($payload['userErrors'] ?? []) as $error) {
                if (is_array($error) && isset($error['message'])) {
                    $messages[] = mb_strtolower((string) $error['message']);
                }
            }
        }

        return $messages;
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
