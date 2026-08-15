<?php

namespace App\Migration\Migrators;

use App\Migration\AbstractMigrator;
use App\Migration\Concerns\ShedsRejectedFields;
use App\Migration\MigrationItemResult;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use App\Services\WooCommerceClient;
use App\Support\PhoneNumber;

/**
 * Migrates WooCommerce orders into Shopify using the orderCreate mutation.
 * Historical orders are created with their original processed date, line items,
 * shipping line, addresses, financial status and a matching sale transaction.
 * Inventory is bypassed and receipts are suppressed (it's a historical import).
 */
class OrderMigrator extends AbstractMigrator
{
    use ShedsRejectedFields;

    private const CREATE_MUTATION = <<<'GQL'
    mutation orderCreate($order: OrderCreateOrderInput!, $options: OrderCreateOptionsInput) {
      orderCreate(order: $order, options: $options) {
        order { id name }
        userErrors { field message }
      }
    }
    GQL;

    public function entityType(): string
    {
        return 'order';
    }

    public function dependsOn(): array
    {
        // Customers first so the order can associate by email to a migrated customer.
        return ['customer'];
    }

    public function count(WoocommerceConnection $connection): int
    {
        return (new WooCommerceClient($connection))->total('orders', ['status' => 'any']);
    }

    /** Source resource for retry-by-id (see AbstractMigrator::fetchByIds). */
    protected function wooSource(): ?array
    {
        return ['orders', ['status' => 'any']];
    }

    public function fetchBatch(WoocommerceConnection $connection, int $page, int $perPage): array
    {
        return (new WooCommerceClient($connection))->list('orders', $page, $perPage, ['status' => 'any']);
    }

    protected function doMigrate(StoreMigration $migration, array $woo, ?string $existingShopifyId = null): MigrationItemResult
    {
        $currency = $woo['currency'] ?? 'USD';
        $email = data_get($woo, 'billing.email');
        $discounts = $this->discountCodes($woo, $currency);

        $order = array_filter([
            'currency' => $currency,
            'email' => $email ?: null,
            'processedAt' => $this->date($woo['date_created'] ?? null),
            'financialStatus' => $this->financialStatus($woo['status'] ?? ''),
            'note' => $this->note($woo, $discounts),
            'tags' => $this->tags($migration, $woo),
            'lineItems' => $this->lineItems($woo, $currency),
            'shippingLines' => $this->shippingLines($woo, $currency),
            'billingAddress' => $this->address($woo['billing'] ?? []),
            'shippingAddress' => $this->address(($woo['shipping'] ?? []) ?: ($woo['billing'] ?? [])),
            'transactions' => $this->transactions($woo, $currency),
            // Shopify accepts a single code per order; discountCodes() sorts the
            // largest first and note() records any others.
            'discountCode' => $discounts[0] ?? null,
        ], fn ($v) => $v !== null && $v !== []);

        if (empty($order['lineItems'])) {
            return MigrationItemResult::failed('Order has no line items');
        }

        $options = [
            'inventoryBehaviour' => 'BYPASS',
            'sendReceipt' => false,
            'sendFulfillmentReceipt' => false,
        ];

        // Line items, currency and financial status make the order what it is;
        // an address or a payment record Shopify rejects is worth a warning, not
        // the loss of the order.
        return $this->writeSheddingBadFields(
            function (array $attempt) use ($migration, $options) {
                $shopify = $this->shopify($migration);
                $body = $shopify->graphql(self::CREATE_MUTATION, ['order' => $attempt, 'options' => $options]);

                // Shopify refusing the REQUEST — no write_orders scope, or (far
                // more likely here) Protected Customer Data not yet approved,
                // which denies orders outright. Folding that into the ordinary
                // errors array sent it round the shedding loop, so one refusal
                // cost four API calls per order and arrived as raw JSON.
                if ($fatal = $this->fatalShopifyError($shopify, $body)) {
                    return MigrationItemResult::failed($fatal);
                }

                return [
                    data_get($body, 'data.orderCreate.order.id'),
                    $shopify->userErrors($body, 'orderCreate'),
                ];
            },
            $order,
            ['transactions', 'shippingAddress', 'billingAddress', 'shippingLines', 'tags', 'note', 'processedAt', 'discountCode'],
        );
    }

    /**
     * The customer's note, plus anything about this order that could not be
     * expressed in Shopify's own fields.
     *
     * A WooCommerce order can carry several coupons and a custom status from a
     * plugin. Shopify has one discount slot and a fixed set of statuses, so the
     * remainder is written down here rather than dropped — a merchant
     * reconciling a historical order needs to be able to see what was actually
     * applied to it.
     */
    private function note(array $woo, array $discounts): ?string
    {
        $parts = [];

        if ($note = trim((string) ($woo['customer_note'] ?? ''))) {
            $parts[] = $note;
        }

        if (count($discounts) > 1) {
            $extra = collect(array_slice($discounts, 1))
                ->map(fn ($d) => data_get($d, 'itemFixedDiscountCode.code'))
                ->filter()
                ->implode(', ');

            if ($extra !== '') {
                $parts[] = "Additional WooCommerce coupons on this order: {$extra}.";
            }
        }

        // A status Shopify has no equivalent for (wc-awaiting-shipment and the
        // like, from an order-management plugin) collapses into PENDING, so the
        // original is recorded before it is lost.
        $status = (string) ($woo['status'] ?? '');
        if ($status !== '' && ! in_array($status, self::KNOWN_STATUSES, true)) {
            $parts[] = "Original WooCommerce status: {$status}.";
        }

        return empty($parts) ? null : mb_substr(implode("\n\n", $parts), 0, 5000);
    }

    private function lineItems(array $woo, string $currency): array
    {
        return collect($woo['line_items'] ?? [])->map(function ($li) use ($currency) {
            $qty = max(1, (int) ($li['quantity'] ?? 1));
            $unit = $this->unitPrice($li, $qty);

            return array_filter([
                // The SKU is deliberately NOT sent. Shopify treats it as a product
                // reference: when it matches no existing variant it silently creates
                // a draft product called "Import placeholder for {sku}" so the line
                // item has something to point at. Importing historical orders that
                // way buried the real catalogue under hundreds of junk drafts.
                // These are custom line items — see the note in /developers/.
                'title' => $this->lineTitle($li),
                'quantity' => $qty,
                'priceSet' => $this->money($unit, $currency),
                'taxLines' => $this->lineTaxes($li, $currency),
            ], fn ($v) => $v !== null && $v !== []);
        })->values()->all();
    }

    /**
     * The PRE-DISCOUNT unit price of one line.
     *
     * This has to be the pre-discount figure, because the coupon is sent
     * separately as `discountCode` and Shopify subtracts it itself. WooCommerce's
     * `price` and `total` are both POST-discount — `subtotal` is the only field
     * that still holds what the line cost before the coupon touched it — so using
     * `price` here took the discount off twice and every migrated order with a
     * coupon on it totalled LESS than the customer actually paid.
     *
     * The fallbacks matter as much as the primary: a line with no `subtotal` (an
     * old order, a plugin-written line) must not silently fall through to a
     * double discount, so `price` is the last resort rather than the first.
     */
    private function unitPrice(array $li, int $qty): float
    {
        foreach (['subtotal', 'total'] as $key) {
            if (isset($li[$key]) && is_numeric($li[$key])) {
                return (float) $li[$key] / $qty;
            }
        }

        return (float) ($li['price'] ?? 0);
    }

    /**
     * Tax charged on one line.
     *
     * Orders used to arrive with no tax on them at all, so a merchant's historical
     * revenue read low by whatever their VAT rate is — and any report built on
     * that data was wrong in a way nobody notices until an accountant does.
     *
     * Shopify wants the rate as a fraction; WooCommerce gives the amount and the
     * line subtotal, so the rate is derived. `priceSet` is what Shopify actually
     * charges against — the rate is descriptive.
     */
    private function lineTaxes(array $li, string $currency): array
    {
        $tax = (float) ($li['total_tax'] ?? 0);

        if ($tax <= 0) {
            return [];
        }

        $net = (float) ($li['total'] ?? 0);

        // No net to divide by (a fully discounted line that still carried tax)
        // means no rate to state. Sending a tax line without one risks Shopify
        // rejecting it — and `lineItems` is NOT sheddable, so that rejection
        // would fail the ENTIRE order rather than costing it this one number.
        // Losing the tax on such a line is the cheaper mistake by a long way.
        if ($net <= 0) {
            return [];
        }

        return [[
            'title' => 'Tax',
            'rate' => round($tax / $net, 4),
            'priceSet' => $this->money($tax, $currency),
        ]];
    }

    /**
     * Order-level discounts, as one Shopify discount code per Woo coupon.
     *
     * WooCommerce records the coupon that was used and what it took off; Shopify
     * needs the amount as a fixed value because the historical rule behind a
     * percentage coupon no longer exists anywhere it can evaluate. Without this
     * the order totalled MORE than the customer actually paid, which is the one
     * direction a merchant will definitely notice.
     */
    private function discountCodes(array $woo, string $currency): array
    {
        return collect($woo['coupon_lines'] ?? [])
            ->map(function ($line) use ($currency) {
                $amount = (float) ($line['discount'] ?? 0);
                $code = trim((string) ($line['code'] ?? ''));

                if ($amount <= 0 || $code === '') {
                    return null;
                }

                return [
                    'itemFixedDiscountCode' => [
                        'code' => mb_substr($code, 0, 255),
                        'amountSet' => $this->money($amount, $currency),
                    ],
                ];
            })
            ->filter()
            // Shopify takes ONE discount code on an order. A Woo order can carry
            // several; the largest is the honest single representation, and the
            // rest are named in the order note so nothing is silently dropped.
            ->sortByDesc(fn ($d) => (float) data_get($d, 'itemFixedDiscountCode.amountSet.shopMoney.amount'))
            ->values()
            ->all();
    }

    /**
     * Keep the SKU visible on the order without handing it to Shopify as a product
     * reference. Losing it entirely would make historical orders harder to reconcile
     * against the catalogue.
     */
    private function lineTitle(array $li): string
    {
        $title = trim((string) ($li['name'] ?? '')) ?: 'Item';
        $sku = trim((string) ($li['sku'] ?? ''));

        return $sku === '' || str_contains($title, $sku)
            ? $title
            : "{$title} (SKU: {$sku})";
    }

    private function shippingLines(array $woo, string $currency): array
    {
        return collect($woo['shipping_lines'] ?? [])->map(fn ($s) => [
            'title' => $s['method_title'] ?? 'Shipping',
            'priceSet' => $this->money($s['total'] ?? 0, $currency),
        ])->values()->all();
    }

    private function transactions(array $woo, string $currency): array
    {
        // Only record a paid transaction for orders that are actually paid.
        if ($this->financialStatus($woo['status'] ?? '') !== 'PAID') {
            return [];
        }

        return [[
            'kind' => 'SALE',
            'status' => 'SUCCESS',
            'amountSet' => $this->money($woo['total'] ?? 0, $currency),
        ]];
    }

    private function address(array $a): ?array
    {
        if (empty($a['address_1']) && empty($a['city'])) {
            return null;
        }

        return array_filter([
            'firstName' => $a['first_name'] ?? null,
            'lastName' => $a['last_name'] ?? null,
            'company' => $a['company'] ?? null,
            'address1' => $a['address_1'] ?? null,
            'address2' => $a['address_2'] ?? null,
            'city' => $a['city'] ?? null,
            'provinceCode' => $a['state'] ?? null,
            'countryCode' => $a['country'] ?? null,
            'zip' => $a['postcode'] ?? null,
            // Shopify needs E.164; WooCommerce stores whatever was typed.
            'phone' => PhoneNumber::toE164($a['phone'] ?? null, $a['country'] ?? null),
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function money($amount, string $currency): array
    {
        return ['shopMoney' => ['amount' => (string) (float) $amount, 'currencyCode' => $currency]];
    }

    /**
     * WooCommerce statuses this maps deliberately. Anything else is a plugin's
     * custom status, which collapses to PENDING — note() records the original so
     * the merchant can still tell what the order really was.
     */
    private const KNOWN_STATUSES = [
        'completed', 'processing', 'refunded', 'cancelled', 'failed', 'pending', 'on-hold', 'checkout-draft',
    ];

    private function financialStatus(string $wooStatus): string
    {
        return match ($wooStatus) {
            'completed', 'processing' => 'PAID',
            'refunded' => 'REFUNDED',
            'cancelled', 'failed' => 'VOIDED',
            default => 'PENDING', // pending, on-hold, etc.
        };
    }

    private function tags(StoreMigration $migration, array $woo): array
    {
        $tags = $migration->customTags();
        if ($number = ($woo['number'] ?? $woo['id'] ?? null)) {
            $tags[] = 'woo-order-' . $number;
        }

        return array_values(array_unique($tags));
    }

    private function date(?string $date): ?string
    {
        return $date ? date('c', strtotime($date)) : null;
    }
}
