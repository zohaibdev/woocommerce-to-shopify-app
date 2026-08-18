<?php

namespace App\Support;

use App\Services\Exceptions\ShopifyThrottledException;

/**
 * How long to wait after Shopify says no, and when to stop waiting.
 *
 * Shared by ProcessBatchJob and RetryFailedItemsJob because they hit exactly the
 * same wall — the retry sweep is the batch loop with a different cursor — and two
 * copies of this would drift. They did: lowering the client's soft-throttle guess
 * silently made the retry sweep give up on a chunk five times sooner, because its
 * own budget counted the short probes.
 *
 * The distinction that drives everything here is measured-vs-guessed. Shopify
 * answers a 429 with Retry-After, and a GraphQL THROTTLED with enough of the
 * point budget to compute the wait exactly — honour those. But its per-mutation
 * limits (orderCreate above all) arrive as an ordinary userError with no timing
 * at all, so the wait is our invention, and a fixed one-minute invention is
 * expensive: a batch managing ~5 orders between refusals spent most of every
 * minute holding a concurrency slot and doing nothing, on a store whose window
 * may well have reopened in ten seconds. Probe early, back off only when the
 * probe is refused.
 */
class ThrottleBackoff
{
    /** @return array<int, int> ascending seconds */
    public static function ladder(): array
    {
        $ladder = array_values(array_filter(
            array_map('intval', (array) config('migration.soft_throttle_backoff', [5, 10, 20, 40, 60])),
            fn (int $seconds) => $seconds > 0,
        ));

        sort($ladder);

        return $ladder ?: [60];
    }

    /**
     * Seconds to wait before trying this chunk again.
     *
     * @param  int  $stalledWaits  consecutive pauses that migrated NOTHING, 1-based.
     *                             Progress resets it, so a merely busy shop never
     *                             climbs the ladder at all.
     */
    public static function delayFor(ShopifyThrottledException $e, int $stalledWaits): int
    {
        if ($e->measured) {
            return max(1, (int) ceil($e->retryAfterSeconds));
        }

        $ladder = self::ladder();
        $step = max(0, min($stalledWaits, count($ladder)) - 1);

        return max((int) ceil($e->retryAfterSeconds), $ladder[$step]);
    }

    /**
     * Extra stalled waits a caller must tolerate on top of its own budget.
     *
     * The ramp is ADDED to a give-up budget rather than spent out of it. A budget
     * of ten means "ten stalls at full backoff"; counting the short probes
     * against it would abandon a chunk on a store with a genuine sixty-second
     * window after about two minutes of trying, turning slow into failed.
     */
    public static function rampAllowance(): int
    {
        return count(self::ladder()) - 1;
    }
}
