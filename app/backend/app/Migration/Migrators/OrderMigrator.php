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

        $order = array_filter([
            'currency' => $currency,
            'email' => $email ?: null,
            'processedAt' => $this->date($woo['date_created'] ?? null),
            'financialStatus' => $this->financialStatus($woo['status'] ?? ''),
            'note' => $woo['customer_note'] ?? null,
            'tags' => $this->tags($migration, $woo),
            'lineItems' => $this->lineItems($woo, $currency),
            'shippingLines' => $this->shippingLines($woo, $currency),
            'billingAddress' => $this->address($woo['billing'] ?? []),
            'shippingAddress' => $this->address(($woo['shipping'] ?? []) ?: ($woo['billing'] ?? [])),
            'transactions' => $this->transactions($woo, $currency),
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

                $errors = $shopify->userErrors($body, 'orderCreate');

                // Surface top-level errors too (e.g. missing write_orders scope).
                if (empty($errors)) {
                    $errors = $shopify->topLevelErrors($body);
                }

                return [data_get($body, 'data.orderCreate.order.id'), $errors];
            },
            $order,
            ['transactions', 'shippingAddress', 'billingAddress', 'shippingLines', 'tags', 'note', 'processedAt'],
        );
    }

    private function lineItems(array $woo, string $currency): array
    {
        return collect($woo['line_items'] ?? [])->map(function ($li) use ($currency) {
            $qty = max(1, (int) ($li['quantity'] ?? 1));
            $unit = $li['price'] ?? (($li['total'] ?? 0) / $qty);

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
            ], fn ($v) => $v !== null);
        })->values()->all();
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
