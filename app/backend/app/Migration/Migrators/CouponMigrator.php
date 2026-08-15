<?php

namespace App\Migration\Migrators;

use App\Migration\AbstractMigrator;
use App\Migration\Concerns\ShedsRejectedFields;
use App\Migration\MigrationItemResult;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use App\Services\WooCommerceClient;

/**
 * WooCommerce coupons -> Shopify discount codes.
 *
 * Woo's three discount types map cleanly enough:
 *
 *   percent        -> percentage off the order
 *   fixed_cart     -> fixed amount off the order
 *   fixed_product  -> fixed amount off each item
 *
 * What does NOT map is the long tail of Woo coupon restrictions — allowed
 * emails, excluded categories, "exclude sale items", per-user limits. Shopify
 * has some of these and not others, and a coupon that silently applies more
 * widely than it used to is worse than one the merchant has to re-create. So
 * anything that cannot be expressed is reported as a warning ON the record
 * rather than quietly dropped.
 */
class CouponMigrator extends AbstractMigrator
{
    use ShedsRejectedFields;

    private const CREATE_MUTATION = <<<'GQL'
    mutation discountCodeBasicCreate($basicCodeDiscount: DiscountCodeBasicInput!) {
      discountCodeBasicCreate(basicCodeDiscount: $basicCodeDiscount) {
        codeDiscountNode { id }
        userErrors { field message }
      }
    }
    GQL;

    /** Restrictions Shopify's basic code discount cannot express. */
    private const UNMAPPABLE = [
        'email_restrictions' => 'restricted to specific email addresses',
        'exclude_sale_items' => 'excluded sale items',
        'excluded_product_ids' => 'excluded specific products',
        'excluded_product_categories' => 'excluded specific categories',
        'limit_usage_to_x_items' => 'limited how many items it applied to',
    ];

    public function entityType(): string
    {
        return 'coupon';
    }

    public function count(WoocommerceConnection $connection): int
    {
        return (new WooCommerceClient($connection))->total('coupons');
    }

    protected function wooSource(): ?array
    {
        return ['coupons', []];
    }

    public function fetchBatch(WoocommerceConnection $connection, int $page, int $perPage): array
    {
        return (new WooCommerceClient($connection))->list('coupons', $page, $perPage);
    }

    protected function doMigrate(StoreMigration $migration, array $woo, ?string $existingShopifyId = null): MigrationItemResult
    {
        $code = trim((string) ($woo['code'] ?? ''));

        if ($code === '') {
            return MigrationItemResult::failed('Coupon has no code');
        }

        $value = $this->value($woo);

        if ($value === null) {
            return MigrationItemResult::failed(
                'Coupon type "'.($woo['discount_type'] ?? 'unknown').'" has no Shopify equivalent',
            );
        }

        $input = array_filter([
            // Shopify uppercases codes at checkout anyway; storing them as the
            // merchant wrote them keeps the admin list recognisable.
            'title' => $code,
            'code' => $code,
            // Required by Shopify on create, and a coupon with no recorded
            // creation date is ordinary in an old store — so this falls back to
            // now() rather than being omitted, which would fail the whole write.
            'startsAt' => $this->date($woo['date_created'] ?? null) ?? now()->toIso8601String(),
            'endsAt' => $this->date($woo['date_expires'] ?? null),
            'customerSelection' => ['all' => true],
            'customerGets' => [
                'value' => $value,
                'items' => ['all' => true],
            ],
            'minimumRequirement' => $this->minimum($woo),
            'usageLimit' => ($limit = (int) ($woo['usage_limit'] ?? 0)) > 0 ? $limit : null,
            'appliesOncePerCustomer' => ((int) ($woo['usage_limit_per_user'] ?? 0)) === 1,
        ], fn ($v) => $v !== null);

        $result = $this->writeSheddingBadFields(
            function (array $attempt) use ($migration) {
                $shopify = $this->shopify($migration);
                $body = $shopify->graphql(self::CREATE_MUTATION, ['basicCodeDiscount' => $attempt]);

                // A missing write_discounts scope is Shopify refusing the request,
                // not an objection to the coupon — shedding fields cannot help.
                if ($fatal = $this->fatalShopifyError($shopify, $body)) {
                    return MigrationItemResult::failed($fatal);
                }

                return [
                    data_get($body, 'data.discountCodeBasicCreate.codeDiscountNode.id'),
                    $shopify->userErrors($body, 'discountCodeBasicCreate'),
                ];
            },
            $input,
            // The code, its value and startsAt are what Shopify requires; an
            // expiry, a minimum or a usage cap it rejects is worth a warning
            // rather than the loss of the coupon. `startsAt` is deliberately NOT
            // here — shedding a required field turns a recoverable error into a
            // guaranteed one.
            ['endsAt', 'minimumRequirement', 'usageLimit', 'appliesOncePerCustomer'],
        );

        return $result->withWarnings($this->unmappable($woo));
    }

    /**
     * Restrictions the merchant has to re-create by hand.
     *
     * Named individually and on the coupon itself: a merchant who reads
     * "migrated" and finds out months later that a staff-only code now works for
     * everyone has been failed by this app, not by Shopify.
     *
     * @return array<int, string>
     */
    private function unmappable(array $woo): array
    {
        $lost = [];

        foreach (self::UNMAPPABLE as $key => $description) {
            if (! empty($woo[$key])) {
                $lost[] = $description;
            }
        }

        if (empty($lost)) {
            return [];
        }

        return ['This coupon '.implode(', ', $lost)
            .' in WooCommerce. Shopify discount codes cannot express that, so the code was migrated '
            .'WITHOUT those restrictions — review it before letting customers use it.'];
    }

    /** Shopify's discount value, or null for a Woo type with no equivalent. */
    private function value(array $woo): ?array
    {
        $amount = (float) ($woo['amount'] ?? 0);

        if ($amount <= 0) {
            return null;
        }

        return match ($woo['discount_type'] ?? '') {
            // Shopify wants a fraction, Woo stores whole percent.
            'percent' => ['percentage' => round($amount / 100, 4)],

            'fixed_cart' => [
                'discountAmount' => ['amount' => $amount, 'appliesOnEachItem' => false],
            ],

            'fixed_product' => [
                'discountAmount' => ['amount' => $amount, 'appliesOnEachItem' => true],
            ],

            default => null,
        };
    }

    private function minimum(array $woo): ?array
    {
        $minimum = (float) ($woo['minimum_amount'] ?? 0);

        return $minimum > 0
            ? ['subtotal' => ['greaterThanOrEqualToSubtotal' => $minimum]]
            : null;
    }

    private function date(?string $date): ?string
    {
        return $date ? date('c', strtotime($date)) : null;
    }
}
