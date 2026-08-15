<?php

namespace App\Migration;

use App\Models\MigrationFile;
use App\Models\StoreMigration;
use App\Services\Exceptions\ShopifyThrottledException;
use App\Services\ShopifyClient;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * Re-hosts the images INSIDE a description on Shopify, and rewrites the HTML to
 * point at them.
 *
 * The gallery images of a product were always migrated properly. The ones pasted
 * into the description — a size chart, a fabric close-up, the photos that make up
 * half of what a long-form product page actually says — were not: descriptionHtml
 * passed through verbatim, so every `<img>` in it still pointed at the merchant's
 * WooCommerce server. That works right up until the day the old store is turned
 * off, which is the entire point of migrating, and then every one of those images
 * breaks at once. A merchant discovers it months later, on the store they now
 * depend on, with the source gone.
 *
 * It is OPT-IN because it is not free. Shopify's fileCreate is asynchronous — the
 * CDN URL does not exist until Shopify has fetched and processed the file — so
 * each new image costs a mutation plus a short poll. Two things keep that in
 * proportion:
 *
 *   CACHED PER SHOP. Description images repeat enormously; they are template
 *   furniture, not per-product photography. migration_files means the shared
 *   banner on a 20,000-product store is uploaded once, not twenty thousand times.
 *
 *   TIME-BOUNDED PER RECORD. One product with thirty new images must not eat the
 *   batch job's whole timeout. Past the budget the remaining images are left
 *   pointing at WooCommerce and the record carries a warning saying so — a
 *   partially re-hosted description is strictly better than a failed product.
 *
 * Only images served BY THE MERCHANT'S OWN STORE are touched. A description that
 * embeds a YouTube thumbnail or a supplier's CDN is left alone: those are not
 * going anywhere when the WooCommerce box is switched off, and copying somebody
 * else's asset into the merchant's Shopify files is not ours to do.
 */
class DescriptionImages
{
    private const CREATE_MUTATION = <<<'GQL'
    mutation fileCreate($files: [FileCreateInput!]!) {
      fileCreate(files: $files) {
        files {
          id
          fileStatus
          ... on MediaImage { image { url } }
        }
        userErrors { field message }
      }
    }
    GQL;

    private const STATUS_QUERY = <<<'GQL'
    query fileStatus($id: ID!) {
      node(id: $id) {
        ... on MediaImage {
          id
          fileStatus
          image { url }
        }
      }
    }
    GQL;

    /** How long to wait for Shopify to finish fetching one image. */
    private const POLL_ATTEMPTS = 6;

    private const POLL_SLEEP_SECONDS = 1;

    /**
     * Seconds spent re-hosting the images of ONE record before we stop and leave
     * the rest as they are. Well under ProcessBatchJob's 300s timeout, because
     * this is only part of what that job does per product.
     */
    private const BUDGET_SECONDS = 20.0;

    /** Shopify rejects a file whose source it cannot fetch; skip the obvious ones. */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'];

    /**
     * Rewrite every self-hosted image in the HTML to its Shopify copy.
     *
     * @param  array<int, string>  $warnings  appended to when something is left behind
     */
    public function rewrite(StoreMigration $migration, ?string $html, array &$warnings = []): ?string
    {
        if (! $this->isEnabled($migration) || ! is_string($html) || $html === '') {
            return $html;
        }

        $sources = $this->selfHostedSources($migration, $html);

        if (empty($sources)) {
            return $html;
        }

        $startedAt = microtime(true);
        $replacements = [];
        $skipped = 0;

        foreach ($sources as $source) {
            if (microtime(true) - $startedAt >= self::BUDGET_SECONDS) {
                $skipped++;

                continue;
            }

            $hosted = $this->hostedUrlFor($migration, $source);

            if ($hosted === null) {
                $skipped++;

                continue;
            }

            $replacements[$source] = $hosted;
        }

        if ($skipped > 0) {
            $warnings[] = "{$skipped} image(s) inside this description could not be copied to Shopify and still "
                .'point at your WooCommerce store — they will stop working if you take it offline.';
        }

        if (empty($replacements)) {
            return $html;
        }

        return strtr($html, $replacements);
    }

    public function isEnabled(StoreMigration $migration): bool
    {
        return (bool) ($migration->options['rehost_description_images'] ?? false);
    }

    /**
     * Every distinct `<img src>` in the HTML that is served by the merchant's own
     * WooCommerce store.
     *
     * @return array<int, string>
     */
    private function selfHostedSources(StoreMigration $migration, string $html): array
    {
        if (! preg_match_all('/<img\b[^>]*?\bsrc\s*=\s*(["\'])(.*?)\1/is', $html, $matches)) {
            return [];
        }

        $storeHost = $this->hostOf((string) $migration->connection?->store_url);

        if ($storeHost === null) {
            return [];
        }

        $sources = [];

        foreach ($matches[2] as $src) {
            $src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5));

            if ($src === '' || str_starts_with($src, 'data:')) {
                continue;
            }

            if ($this->hostOf($src) !== $storeHost) {
                continue;
            }

            // Shopify's file API takes images. A link to a PDF in an <img> is
            // malformed content that would only earn a userError.
            $extension = strtolower((string) pathinfo((string) parse_url($src, PHP_URL_PATH), PATHINFO_EXTENSION));

            if ($extension !== '' && ! in_array($extension, self::IMAGE_EXTENSIONS, true)) {
                continue;
            }

            $sources[$src] = true;
        }

        return array_keys($sources);
    }

    private function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower(ltrim($host, 'www.')) : null;
    }

    /**
     * The Shopify URL for one source image — from the shop's own record of what
     * it has already uploaded, or by uploading it now.
     */
    private function hostedUrlFor(StoreMigration $migration, string $source): ?string
    {
        $hash = MigrationFile::hashFor($source);

        $existing = MigrationFile::where('shop_id', $migration->shop_id)
            ->where('source_hash', $hash)
            ->value('shopify_url');

        if ($existing) {
            return $existing;
        }

        $uploaded = $this->upload($migration, $source);

        if ($uploaded === null) {
            return null;
        }

        [$fileId, $url] = $uploaded;

        try {
            MigrationFile::create([
                'shop_id' => $migration->shop_id,
                'source_hash' => $hash,
                'source_url' => $source,
                'shopify_url' => $url,
                'shopify_file_id' => $fileId,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two parallel batches hit the same shared image at the same moment.
            // Both uploads are real files in Shopify; keeping the first record is
            // enough, and the duplicate is a stray file rather than a broken page.
        }

        return $url;
    }

    /**
     * Hand the URL to Shopify and wait for it to become servable.
     *
     * @return array{0: string|null, 1: string}|null [fileId, cdnUrl]
     */
    private function upload(StoreMigration $migration, string $source): ?array
    {
        $shopify = new ShopifyClient($migration->shop);

        try {
            $body = $shopify->graphql(self::CREATE_MUTATION, [
                'files' => [[
                    'originalSource' => $source,
                    'contentType' => 'IMAGE',
                ]],
            ]);
        } catch (Throwable $e) {
            // Deliberately swallowed. A throttle is re-thrown by the client and
            // handled by the batch; anything else here is one image in one
            // description, and it must never cost the product.
            if ($e instanceof ShopifyThrottledException) {
                throw $e;
            }

            return null;
        }

        if (! empty($shopify->userErrors($body, 'fileCreate')) || ! empty($shopify->topLevelErrors($body))) {
            return null;
        }

        $fileId = data_get($body, 'data.fileCreate.files.0.id');
        $url = data_get($body, 'data.fileCreate.files.0.image.url');

        if ($url) {
            return [$fileId, $url];
        }

        if (! $fileId) {
            return null;
        }

        return $this->waitForUrl($shopify, $fileId);
    }

    /**
     * fileCreate returns before Shopify has fetched the image, so the CDN URL is
     * usually not in the mutation's own response. Poll for it, briefly.
     *
     * @return array{0: string, 1: string}|null
     */
    private function waitForUrl(ShopifyClient $shopify, string $fileId): ?array
    {
        for ($attempt = 0; $attempt < self::POLL_ATTEMPTS; $attempt++) {
            sleep(self::POLL_SLEEP_SECONDS);

            try {
                $body = $shopify->graphql(self::STATUS_QUERY, ['id' => $fileId]);
            } catch (Throwable $e) {
                if ($e instanceof ShopifyThrottledException) {
                    throw $e;
                }

                return null;
            }

            $status = data_get($body, 'data.node.fileStatus');

            if ($status === 'FAILED') {
                return null;
            }

            if ($url = data_get($body, 'data.node.image.url')) {
                return [$fileId, $url];
            }
        }

        // Still processing. The image is genuinely in Shopify, but we have no URL
        // to write into the description, so the original stays and the record
        // says so rather than shipping a broken <img>.
        return null;
    }
}
