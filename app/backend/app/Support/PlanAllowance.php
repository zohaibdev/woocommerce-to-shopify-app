<?php

namespace App\Support;

use App\Models\MigrationItem;
use App\Models\Shop;

/**
 * What a shop's plan actually lets it migrate for one entity, right now.
 *
 * The wizard used to offer every entity regardless of plan, so a merchant on the
 * free plan could select 11,744 products, start a run, and only afterwards find a
 * buried log line saying 10 were migrated. This turns the plan into something the
 * UI can show up front — and the API can enforce.
 *
 * Two kinds of metering are supported and they compose:
 *
 *   PER-ENTITY  `limits.products` etc. A separate cap for each type.
 *   POOLED      `limits.total`. One shared record budget across products,
 *               customers and orders — see App\Support\PlanPool.
 *
 * When a plan has both, the tighter of the two wins.
 */
class PlanAllowance
{
    /**
     * @param  int  $available  how many records exist in WooCommerce
     * @param  PlanPool|null  $pool  a pool to consult AND SPEND. Pass the same
     *                               instance across entities, in dependency
     *                               order, so the preview allocates exactly the
     *                               way StartMigrationJob will.
     * @return array{limit: int|null, used: int, remaining: int|null, migratable: int, locked: bool, exhausted: bool, pooled: bool, reason: string|null}
     */
    public static function forEntity(Shop $shop, string $entityType, int $available, ?PlanPool $pool = null): array
    {
        // null = unlimited, 0 = the plan doesn't include this entity at all.
        $limit = $shop->limitFor($entityType);

        // Counted over the CURRENT BILLING PERIOD, not all time. Measuring it
        // over all time made a recurring subscription behave like a one-off
        // purchase — spend the allowance once and every later invoice bought
        // nothing. See App\Support\BillingPeriod.
        //
        // `created_at` deliberately, not `updated_at`: a record migrated in an
        // earlier period and re-migrated now had its row UPDATED, not created,
        // so re-running to refresh existing data stays free exactly as before.
        // The allowance is for records this shop has not migrated yet.
        $used = MigrationItem::query()
            ->where('shop_id', $shop->id)
            ->where('entity_type', $entityType)
            ->where('status', 'success')
            ->where('created_at', '>=', BillingPeriod::currentStart($shop))
            ->count();

        $remaining = $limit === null ? null : max(0, $limit - $used);

        // Matches StartMigrationJob's cap exactly, so the preview never promises
        // more than the run will do.
        $migratable = $limit === null ? $available : min($available, $limit);

        $locked = $limit === 0;

        $pooled = $pool !== null && $pool->meters($entityType) && ! $locked;

        if ($pooled) {
            $beforePool = $migratable;
            $migratable = $pool->claim($entityType, $migratable);
            $poolCapped = $migratable < $beforePool;
        } else {
            $poolCapped = false;
        }

        // "Exhausted" only when the cap is spent AND there is still source data it
        // can never reach. If everything already fits under the cap, re-running is
        // legitimate — it refreshes what is there.
        $exhausted = ! $locked && (
            $pooled
                ? ($migratable === 0 && $available > 0)
                : ($limit !== null && $remaining === 0 && $available > $limit)
        );

        $planName = $shop->currentPlan()?->name ?? 'current';

        // "You've used it all" is a dead end; "you've used it all, it comes back
        // on the 12th" is a plan. An exhausted merchant with no date in front of
        // them has no reason to believe waiting will help, so they either
        // upgrade for the wrong reason or leave.
        $resetsOn = BillingPeriod::nextReset($shop)->format('j M');

        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => $remaining,
            'migratable' => $migratable,
            'locked' => $locked,
            'exhausted' => $exhausted,
            // Lets the wizard explain a cap the merchant cannot work out from
            // this entity's own numbers, because it was set by another entity
            // spending the shared budget first.
            'pooled' => $pooled,
            'reason' => match (true) {
                $locked => "Not included in the {$planName} plan.",
                $pooled && $exhausted => "You've used all ".number_format((int) $pool->limit)
                    ." records in this month's {$planName} allowance — it resets on {$resetsOn}.",
                $poolCapped => "Your {$planName} plan covers ".number_format((int) $pool->limit)
                    .' records in total — '.number_format($migratable).' of '
                    .number_format($available)." {$entityType} records fit in what is left.",
                $exhausted => "You've used all {$limit} of this month's {$planName} allowance — it resets on {$resetsOn}.",
                $limit !== null && $available > $limit => "Your {$planName} plan covers {$limit} of {$available}.",
                default => null,
            },
        ];
    }

    /** Entity types the shop's plan does not include at all. */
    public static function lockedAmong(Shop $shop, array $entityTypes): array
    {
        return array_values(array_filter(
            $entityTypes,
            fn (string $type) => $shop->limitFor($type) === 0,
        ));
    }
}
