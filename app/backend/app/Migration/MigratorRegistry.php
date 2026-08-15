<?php

namespace App\Migration;

use App\Migration\Contracts\Migrator;
use App\Migration\Migrators\ArticleMigrator;
use App\Migration\Migrators\AttributeMigrator;
use App\Migration\Migrators\BlogMigrator;
use App\Migration\Migrators\CategoryMigrator;
use App\Migration\Migrators\CouponMigrator;
use App\Migration\Migrators\CustomerMigrator;
use App\Migration\Migrators\MediaMigrator;
use App\Migration\Migrators\OrderMigrator;
use App\Migration\Migrators\ProductMigrator;
use App\Migration\Migrators\ProductVariationMigrator;
use App\Migration\Migrators\ReviewMigrator;
use InvalidArgumentException;

class MigratorRegistry
{
    /** @var array<string, class-string<Migrator>> */
    private array $map = [
        'category' => CategoryMigrator::class,
        'attribute' => AttributeMigrator::class,
        'product' => ProductMigrator::class,
        'variation' => ProductVariationMigrator::class,
        'media' => MediaMigrator::class,
        'customer' => CustomerMigrator::class,
        'order' => OrderMigrator::class,
        'coupon' => CouponMigrator::class,
        'blog' => BlogMigrator::class,
        'article' => ArticleMigrator::class,
        'review' => ReviewMigrator::class,
    ];

    public function has(string $entityType): bool
    {
        return isset($this->map[$entityType]);
    }

    public function make(string $entityType): Migrator
    {
        if (! $this->has($entityType)) {
            throw new InvalidArgumentException("Unknown entity type: {$entityType}");
        }

        return app($this->map[$entityType]);
    }

    /**
     * Order the selected entity types so dependencies run first (topological sort).
     *
     * @param  array<int, string>  $selected
     * @return array<int, string>
     */
    public function order(array $selected): array
    {
        $selected = array_values(array_unique($selected));
        $ordered = [];
        $visiting = [];

        $visit = function (string $type) use (&$visit, &$ordered, &$visiting, $selected) {
            if (in_array($type, $ordered, true)) {
                return;
            }
            if (isset($visiting[$type])) {
                return; // guard against cycles
            }
            $visiting[$type] = true;

            foreach ($this->make($type)->dependsOn() as $dep) {
                if (in_array($dep, $selected, true)) {
                    $visit($dep);
                }
            }

            unset($visiting[$type]);
            $ordered[] = $type;
        };

        foreach ($selected as $type) {
            $visit($type);
        }

        return $ordered;
    }
}
