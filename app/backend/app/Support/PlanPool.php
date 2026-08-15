<?php

namespace App\Support;

use App\Models\MigrationItem;
use App\Models\Shop;

/**
 * A plan allowance that is SHARED across entity types rather than counted per
 * type.
 *
 * The per-entity caps ("20,000 products AND 20,000 customers AND 20,000 orders")
 * are three separate promises, which means the headline number tells a merchant
 * almost nothing about what they are buying — a store with 20,000 products and
 * nothing else gets the same plan as one with 60,000 records across all three.
 * A pool is one number: `limits.total` records, spent however the merchant likes.
 *
 * Set `limits.total` on a plan to meter it this way. Plans without it keep the
 * per-entity behaviour exactly as before, so this is additive.
 *
 * TWO RULES make the arithmetic behave the way merchants expect:
 *
 *  1. The pool counts DISTINCT records, not migration runs. migration_items is
 *     unique per (shop, entity, source id), so re-migrating a record the shop has
 *     already migrated costs nothing — otherwise a merchant with 15,000 products
 *     under a 20,000 pool could re-run their catalogue exactly once before the
 *     app refused to touch it again.
 *
 *  2. Entities claim in DEPENDENCY ORDER, first come first served, and each one
 *     may always reclaim what it already holds. So an entity's ceiling is
 *     "everything of mine that is already counted, plus whatever is left of the
 *     shared pool".
 */
class PlanPool
{
    /**
     * Entity types that draw on the shared allowance.
     *
     * Categories are deliberately absent: no plan has ever metered them, they are
     * a tiny fraction of any catalogue, and charging a merchant's record budget
     * for the collections their products need would be a surprise.
     */
    public const METERED = ['product', 'customer', 'order'];

    /**
     * @param  int|null  $limit  null when this plan has no shared pool at all
     * @param  array<string, int>  $usedByEntity  distinct records already migrated
     * @param  int|null  $remaining  null when unmetered
     */
    private function __construct(
        public readonly ?int $limit,
        private readonly array $usedByEntity,
        private ?int $remaining,
    ) {}

    public static function for(Shop $shop): self
    {
        $limit = $shop->poolLimit();

        if ($limit === null) {
            return new self(null, [], null);
        }

        $used = MigrationItem::query()
            ->where('shop_id', $shop->id)
            ->whereIn('entity_type', self::METERED)
            ->where('status', 'success')
            ->selectRaw('entity_type, count(*) as aggregate')
            ->groupBy('entity_type')
            ->pluck('aggregate', 'entity_type')
            ->map(fn ($n) => (int) $n)
            ->all();

        return new self($limit, $used, max(0, $limit - array_sum($used)));
    }

    /** Whether this plan meters anything as a shared pool. */
    public function isActive(): bool
    {
        return $this->limit !== null;
    }

    public function meters(string $entityType): bool
    {
        return $this->isActive() && in_array($entityType, self::METERED, true);
    }

    /** Distinct records this shop has already migrated across the pooled types. */
    public function used(): int
    {
        return array_sum($this->usedByEntity);
    }

    public function usedFor(string $entityType): int
    {
        return $this->usedByEntity[$entityType] ?? 0;
    }

    public function remaining(): ?int
    {
        return $this->remaining;
    }

    /**
     * The most records of this entity the pool allows — and spend it.
     *
     * Only capacity BEYOND what this entity already holds draws the pool down,
     * which is what makes a re-run free (rule 1 above). Call once per entity, in
     * dependency order, so preview and enforcement allocate identically.
     */
    public function claim(string $entityType, int $wanted): int
    {
        if (! $this->meters($entityType)) {
            return $wanted;
        }

        $alreadyMine = $this->usedFor($entityType);
        $granted = min($wanted, $alreadyMine + (int) $this->remaining);

        $this->remaining = max(0, $this->remaining - max(0, $granted - $alreadyMine));

        return $granted;
    }

    /** What claim() would grant, without spending anything. */
    public function preview(string $entityType, int $wanted): int
    {
        if (! $this->meters($entityType)) {
            return $wanted;
        }

        return min($wanted, $this->usedFor($entityType) + (int) $this->remaining);
    }

    /** @return array{limit: int, used: int, remaining: int, entities: array<int, string>} */
    public function toArray(): array
    {
        return [
            'limit' => (int) $this->limit,
            'used' => $this->used(),
            'remaining' => (int) $this->remaining,
            'entities' => self::METERED,
        ];
    }
}
