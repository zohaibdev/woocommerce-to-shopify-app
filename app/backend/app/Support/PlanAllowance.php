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
 */
class PlanAllowance
{
    /**
     * @param  int  $available  how many records exist in WooCommerce
     * @return array{limit: int|null, used: int, remaining: int|null, migratable: int, locked: bool, exhausted: bool, reason: string|null}
     */
    public static function forEntity(Shop $shop, string $entityType, int $available): array
    {
        // null = unlimited, 0 = the plan doesn't include this entity at all.
        $limit = $shop->limitFor($entityType);

        $used = MigrationItem::query()
            ->where('shop_id', $shop->id)
            ->where('entity_type', $entityType)
            ->where('status', 'success')
            ->count();

        $remaining = $limit === null ? null : max(0, $limit - $used);

        // Matches StartMigrationJob's cap exactly, so the preview never promises
        // more than the run will do.
        $migratable = $limit === null ? $available : min($available, $limit);

        $locked = $limit === 0;

        // "Exhausted" only when the cap is spent AND there is still source data it
        // can never reach. If everything already fits under the cap, re-running is
        // legitimate — it refreshes what is there.
        $exhausted = ! $locked && $limit !== null && $remaining === 0 && $available > $limit;

        $planName = $shop->currentPlan()?->name ?? 'current';

        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => $remaining,
            'migratable' => $migratable,
            'locked' => $locked,
            'exhausted' => $exhausted,
            'reason' => match (true) {
                $locked => "Not included in the {$planName} plan.",
                $exhausted => "You've used all {$limit} of your {$planName} plan allowance.",
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
