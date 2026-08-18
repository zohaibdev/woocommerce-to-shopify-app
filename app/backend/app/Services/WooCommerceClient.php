<?php

namespace App\Services;

use App\Models\WoocommerceConnection;
use App\Services\Exceptions\WooCommerceUnavailableException;
use App\Support\MigrationDiagnostics;
use App\Support\SafeCache;
use App\Support\SourceHealth;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the WooCommerce REST API v3.
 * Auth via consumer key/secret (Basic auth over HTTPS).
 *
 * Every request is timed and reported to SourceHealth, which is how a merchant
 * finds out their own host — not the app — is what is making a run crawl.
 */
class WooCommerceClient
{
    /**
     * Woo product pages carry full meta_data and image sets, and a shared
     * WordPress host can take a long time to assemble one. 30s was cutting off
     * pages that would have arrived at 40s, turning a slow store into a failed
     * batch.
     */
    private const TIMEOUT_SECONDS = 60;

    /** Page size used when the count headers are missing and we have to probe. */
    private const PROBE_PER_PAGE = 100;

    /** Ceiling on the probe, so a broken endpoint cannot make us walk forever. */
    private const PROBE_MAX_PAGES = 5000;

    public function __construct(private readonly WoocommerceConnection $connection) {}

    /**
     * The API base a resource belongs to.
     *
     * Products, orders and coupons live in WooCommerce's own namespace; posts,
     * pages and post categories are plain WordPress and live in `wp/v2`. Letting
     * a resource name its namespace keeps one paginated, timed, retrying client
     * serving both instead of growing a second copy of all of it.
     *
     * NOTE: the consumer key/secret authenticates `wc/v3`, not `wp/v2`. Published
     * WordPress content is readable without authentication, so this works — but
     * drafts and private pages are not visible to us, which is why the content
     * migrators only ever claim to migrate published records.
     */
    private function baseFor(string $resource): string
    {
        $root = rtrim($this->connection->store_url, '/').'/wp-json/';

        return str_starts_with($resource, 'wp/v2/') ? $root : $root.'wc/v3/';
    }

    private function client(?string $baseUrl = null): PendingRequest
    {
        $base = $baseUrl ?? rtrim($this->connection->store_url, '/').'/wp-json/wc/v3/';

        return Http::baseUrl($base)
            ->withBasicAuth($this->connection->consumer_key, $this->connection->consumer_secret)
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            // Retry only transient failures (connection/5xx/429). Never retry a
            // 4xx like 401/403 — the credentials won't become valid on retry.
            ->retry(3, 1000, function ($exception) {
                $status = $exception instanceof \Illuminate\Http\Client\RequestException
                    ? $exception->response->status()
                    : 0;

                return $status === 0 || $status >= 500 || $status === 429;
            }, throw: false);
    }

    /**
     * Issue a request and report what it cost us. Timing every call is the whole
     * basis for telling the merchant their store is the bottleneck.
     */
    private function timed(callable $request): Response
    {
        $startedAt = microtime(true);

        // SourceHealth reports a rolling average to the MERCHANT in plain words;
        // this reports the raw total per job to the developer log. Both, because
        // "your store averages 12s a request" and "this job spent 40 of its 35
        // seconds waiting on WooCommerce" answer different questions.
        MigrationDiagnostics::bump('woo_calls');

        try {
            $response = $request();
        } catch (\Throwable $e) {
            $elapsed = microtime(true) - $startedAt;
            MigrationDiagnostics::add('woo_seconds', $elapsed);
            MigrationDiagnostics::bump('woo_failures');
            SourceHealth::record($elapsed, failed: true);

            throw $e;
        }

        $elapsed = microtime(true) - $startedAt;
        MigrationDiagnostics::add('woo_seconds', $elapsed);

        SourceHealth::record(
            $elapsed,
            // 4xx is a credentials/permission problem, not an unhealthy host.
            failed: $response->serverError() || $response->status() === 429,
        );

        return $response;
    }

    /** Test connection; returns [ok, wc_version, wp_version, error]. */
    public function test(): array
    {
        // system_status requires read scope; fall back to a lightweight products call.
        $res = $this->timed(fn () => $this->client()->get('system_status'));

        if ($res->successful()) {
            $data = $res->json();

            return [
                'ok' => true,
                'wc_version' => data_get($data, 'environment.version'),
                'wp_version' => data_get($data, 'environment.wp_version'),
                'error' => null,
            ];
        }

        $probe = $this->timed(fn () => $this->client()->get('products', ['per_page' => 1]));

        return [
            'ok' => $probe->successful(),
            'wc_version' => null,
            'wp_version' => null,
            'error' => $probe->successful() ? null : "HTTP {$probe->status()}: {$probe->body()}",
        ];
    }

    /**
     * Total count for a resource.
     *
     * Normally one request: WordPress reports it in `X-WP-Total`. But plenty of
     * real stores never send that header — a security plugin strips it, a page
     * cache or reverse proxy drops unknown headers, or the endpoint is behind
     * Cloudflare. This used to return 0, and 0 does not read as "we could not
     * count" — it reads as "there is nothing to migrate", so the whole run would
     * finish in seconds having moved not one record and reported success.
     *
     * So when the header is absent we go and find out, by probing for the last
     * page. It costs roughly 2·log₂(pages) requests — about twenty for a 50k
     * catalogue — and only ever runs on stores that gave us no other option.
     */
    public function total(string $resource, array $query = []): int
    {
        $res = $this->timed(fn () => $this->client($this->baseFor($resource))->get($resource, array_merge(['per_page' => 1], $query)));

        // With per_page=1 the page count IS the record count, so either header
        // answers the question.
        foreach (['X-WP-Total', 'X-WP-TotalPages'] as $header) {
            $value = $res->header($header);

            if ($value !== '' && $value !== null && is_numeric($value)) {
                return (int) $value;
            }
        }

        // A refused request is not an empty store. Returning 0 here meant expired
        // API keys — the single most common WooCommerce problem there is —
        // presented to the merchant as "you have no products", with the run
        // completing in seconds having reported success. Throwing puts it in
        // front of them: StartMigrationJob records the entity as failed and
        // writes the reason, and the wizard reports it before they start.
        if (! $res->successful()) {
            throw new WooCommerceUnavailableException(
                $res->status() === 401 || $res->status() === 403
                    ? 'WooCommerce refused the request (HTTP '.$res->status().'). Check that the API key and '
                        .'secret are still valid and have Read permission.'
                    : "WooCommerce returned HTTP {$res->status()} when asked how many {$resource} it has.",
                $res->status(),
            );
        }

        SourceHealth::note(
            'warning',
            'Your store did not report how many records it has, so they are being counted page by page. '
                .'This is normal on hosts with a security plugin or a caching proxy — it only makes the first minute slower.',
        );

        // Remembered briefly, because the probe is the one genuinely expensive
        // read this client makes and it is made TWICE for every migration: once
        // by the wizard's preview and again minutes later by StartMigrationJob.
        //
        // The preview is the dangerous one — it counts eight entities inside a
        // single HTTP request, with no job timeout to hide behind, and on a store
        // that hides its count headers each of those is ~2·log₂(pages) of the
        // slowest queries on the site. That is how a large catalogue turned the
        // wizard into a 504.
        //
        // Deliberately short, and deliberately ONLY the probe path: the header
        // path is one cheap request and is always read fresh, so a store that
        // reports its own counts is never served a stale number.
        return (int) SafeCache::remember(
            $this->probeCacheKey($resource, $query),
            now()->addMinutes(5),
            fn () => $this->countByProbe($resource, $query),
        );
    }

    /** Keyed by store + resource + filter, never by connection row id — the
     * candidate connection tested during setup has no id yet. */
    private function probeCacheKey(string $resource, array $query): string
    {
        return 'woo:count-probe:'.sha1(implode('|', [
            rtrim((string) $this->connection->store_url, '/'),
            $resource,
            json_encode($query),
        ]));
    }

    /**
     * Find the record count by locating the last non-empty page.
     *
     * Exponential search to bracket the boundary, then a binary search inside the
     * bracket. Only ids are requested, so each probe is a few hundred bytes
     * rather than a full page of products with their meta.
     */
    private function countByProbe(string $resource, array $query): int
    {
        $per = self::PROBE_PER_PAGE;

        /** Items on a page, or null if the request failed. */
        $size = function (int $page) use ($resource, $query, $per): ?int {
            $res = $this->timed(fn () => $this->client($this->baseFor($resource))->get($resource, array_merge([
                'page' => $page,
                'per_page' => $per,
                'orderby' => 'id',
                'order' => 'asc',
                '_fields' => 'id',
            ], $query)));

            // Woo answers 400 for a page past the end rather than an empty list.
            if ($res->status() === 400 || $res->status() === 404) {
                return 0;
            }

            return $res->successful() ? count($res->json() ?? []) : null;
        };

        $first = $size(1);

        if ($first === null) {
            return 0;
        }
        if ($first < $per) {
            return $first;
        }

        // Bracket: $low is a known-full page, $high the first short/empty one.
        $low = 1;
        $high = 2;

        while (true) {
            if ($high >= self::PROBE_MAX_PAGES) {
                return $low * $per;
            }

            $n = $size($high);

            if ($n === null) {
                return $low * $per; // at least this much; better than claiming zero
            }
            if ($n < $per) {
                break;
            }

            $low = $high;
            $high *= 2;
        }

        while ($low + 1 < $high) {
            $mid = intdiv($low + $high, 2);
            $n = $size($mid);

            if ($n === null) {
                return $low * $per;
            }

            if ($n < $per) {
                $high = $mid;
            } else {
                $low = $mid;
            }
        }

        return ($high - 1) * $per + max(0, (int) $size($high));
    }

    /**
     * Fetch a paginated list.
     *
     * Ordering is pinned to ascending id unless the caller says otherwise. Woo
     * defaults products to `date DESC`, which means one product published during
     * a multi-hour run shifts every later page by one and a record silently
     * falls through the gap between pages. Ids only ever grow, so new rows land
     * at the end where they cannot disturb pages already read.
     */
    public function list(string $resource, int $page, int $perPage, array $query = []): array
    {
        $res = $this->timed(fn () => $this->client($this->baseFor($resource))->get($resource, array_merge([
            'page' => $page,
            'per_page' => $perPage,
            'orderby' => 'id',
            'order' => 'asc',
        ], $query)));

        $res->throw();

        return $res->json() ?? [];
    }

    /**
     * The ids on one page, and nothing else.
     *
     * `_fields=id` does not make WordPress's OFFSET any cheaper, but it skips the
     * expensive half of a Woo page: assembling every product's images, meta_data,
     * attributes and variation links into the response. That is where most of the
     * 30-90s on a deep page of a large catalogue actually goes, so this answers in
     * a fraction of the time and a fraction of the bytes.
     *
     * The caller pins the result to the batch and fetches the real records with
     * `include=` from then on, which costs the same at page 500 as at page 1.
     *
     * Returns null for "could not tell" (a refused or malformed response) so the
     * caller can fall back to ordinary paging rather than mistake it for an empty
     * page and skip records that are really there.
     *
     * @return array<int, int>|null
     */
    public function listIds(string $resource, int $page, int $perPage, array $query = []): ?array
    {
        $res = $this->timed(fn () => $this->client($this->baseFor($resource))->get($resource, array_merge([
            'page' => $page,
            'per_page' => $perPage,
            'orderby' => 'id',
            'order' => 'asc',
            '_fields' => 'id',
        ], $query)));

        if (! $res->successful()) {
            return null;
        }

        $body = $res->json();

        if (! is_array($body)) {
            return null;
        }

        $ids = [];

        foreach ($body as $row) {
            // A resource that ignores `_fields` still returns whole objects, which
            // is fine — we only ever wanted the id off the front of them.
            $id = is_array($row) ? ($row['id'] ?? null) : $row;

            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        // Some rows came back without an id we could read: the shape is not what
        // this assumes, so say so instead of silently dropping those records.
        if (count($ids) !== count($body)) {
            return null;
        }

        return $ids;
    }

    /**
     * Read WordPress attachment records (wp/v2, not wc/v3) so we can pick a
     * smaller generated image size. Returns [] when the endpoint is unavailable
     * — plenty of hosts lock wp/v2 down, and that must degrade to "use the
     * original image", never to an error.
     *
     * @param  array<int, int>  $ids  attachment ids (max 100 per call)
     * @return array<int, array> keyed by attachment id
     */
    public function mediaDetails(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $base = rtrim($this->connection->store_url, '/').'/wp-json/wp/v2/';

        try {
            $res = $this->timed(fn () => $this->client($base)->get('media', [
                'include' => implode(',', array_map('intval', $ids)),
                'per_page' => min(100, count($ids)),
                '_fields' => 'id,media_details',
            ]));
        } catch (\Throwable) {
            return [];
        }

        if (! $res->successful()) {
            return [];
        }

        $byId = [];

        foreach ($res->json() ?? [] as $item) {
            if (isset($item['id'])) {
                $byId[(int) $item['id']] = $item;
            }
        }

        return $byId;
    }
}
