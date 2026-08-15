<?php

namespace App\Migration;

use App\Models\StoreMigration;
use App\Services\WooCommerceClient;
use App\Support\SafeCache;

/**
 * Picks a smaller already-generated copy of each WooCommerce image.
 *
 * Shopify downloads every image itself, and productSet runs synchronously, so
 * the product write does not return until Shopify has pulled the file. A store
 * whose gallery is full of 8 MB camera originals therefore spends most of the
 * migration waiting on image transfers rather than on anything Shopify or we are
 * doing. Handing Shopify a 1024px copy of the same picture is often 10-20x less
 * to move, and for a storefront it is the size that actually gets displayed.
 *
 * WordPress has already made those copies — every upload is resized into
 * `thumbnail`/`medium`/`large`/etc. The catch is that their URLs cannot be
 * guessed: WP names them by their real scaled dimensions (`photo-1024x768.jpg`),
 * and the height depends on the source aspect ratio. So we ask, via wp/v2/media,
 * in one bulk request per page rather than one per image.
 *
 * Every failure path leads back to the original URL. Losing image QUALITY is a
 * trade the merchant opted into; losing the IMAGE is not.
 */
class ImageSizeResolver
{
    /** Target widths, in px. `full` means send the original untouched. */
    public const SIZES = [
        'standard' => 1024,
        'large' => 1536,
        'full' => null,
    ];

    /**
     * attachment id => [width => source_url], for the page being processed.
     * Page-scoped rather than cached: a run of 50k products would otherwise put
     * a quarter of a million rows into the cache table for no benefit.
     *
     * @var array<int, array<int, string>>
     */
    private array $sizesById = [];

    /**
     * Load the size table for these images in one go.
     *
     * @param  array<int, int>  $imageIds
     * @param  bool  $reset  start a new page (otherwise the ids are added to what is known)
     */
    public function prefetch(StoreMigration $migration, array $imageIds, bool $reset = false): void
    {
        if ($reset) {
            $this->sizesById = [];
        }

        // Guarded here and not only in the caller: a merchant who asked for
        // full-size images must never have their store asked for size tables it
        // will not be used for.
        if (! $this->isActive($migration)) {
            return;
        }

        // Anything already looked up on this page costs nothing to reuse.
        $imageIds = array_values(array_diff(
            array_unique(array_filter($imageIds)),
            array_keys($this->sizesById),
        ));

        if (empty($imageIds)) {
            return;
        }

        $client = new WooCommerceClient($migration->connection);
        $found = false;

        // wp/v2 caps `include` at the page size, so ask in hundreds.
        foreach (array_chunk($imageIds, 100) as $chunk) {
            $media = $client->mediaDetails($chunk);

            if (! empty($media)) {
                $found = true;
            }

            foreach ($media as $id => $item) {
                $sizes = [];

                foreach (data_get($item, 'media_details.sizes', []) as $size) {
                    $width = (int) ($size['width'] ?? 0);
                    $url = $size['source_url'] ?? null;

                    if ($width > 0 && $url) {
                        $sizes[$width] = $url;
                    }
                }

                if (! empty($sizes)) {
                    ksort($sizes);
                    $this->sizesById[(int) $id] = $sizes;
                }
            }
        }

        // Plenty of hosts lock wp/v2 down or sit behind a security plugin. Say so
        // once and stop asking for the rest of the run.
        if (! $found) {
            $this->disable($migration);
        }
    }

    /**
     * The URL to hand Shopify for one image.
     *
     * @param  int|null  $id  WooCommerce attachment id, when known
     * @param  string  $originalSrc  the full-size URL Woo gave us
     */
    public function url(StoreMigration $migration, ?int $id, string $originalSrc): string
    {
        $target = $this->targetWidth($migration);

        if ($target === null || ! $id) {
            return $originalSrc;
        }

        $sizes = $this->sizesById[$id] ?? null;

        if (empty($sizes)) {
            return $originalSrc;
        }

        // Largest generated copy that still fits under the target. If the original
        // is already smaller than the target, nothing here beats it and we fall
        // through to it unchanged.
        $best = null;

        foreach ($sizes as $width => $url) {
            if ($width <= $target) {
                $best = $url;
            }
        }

        return $best ?? $originalSrc;
    }

    /** Whether this migration is resizing at all (false = sending originals). */
    public function isActive(StoreMigration $migration): bool
    {
        return $this->targetWidth($migration) !== null && ! $this->isDisabled($migration);
    }

    /** Target width in px for this migration, or null to send originals. */
    public function targetWidth(StoreMigration $migration): ?int
    {
        $choice = $migration->options['image_size']
            ?? config('migration.image_size', 'standard');

        return self::SIZES[$choice] ?? null;
    }

    private function disable(StoreMigration $migration): void
    {
        if ($this->isDisabled($migration)) {
            return;
        }

        SafeCache::put($this->disabledKey($migration), true, now()->addDay());

        MigrationRunner::log(
            $migration->id,
            'info',
            'product',
            'Could not read image sizes from WordPress, so images are being migrated at their original size. '
                .'This is safe, but large images make the migration slower.',
        );
    }

    private function isDisabled(StoreMigration $migration): bool
    {
        return (bool) SafeCache::get($this->disabledKey($migration), false);
    }

    private function disabledKey(StoreMigration $migration): string
    {
        return "migration:{$migration->id}:image_sizes_unavailable";
    }
}
