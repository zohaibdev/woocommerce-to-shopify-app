<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Shop;
use App\Models\Subscription;
use RuntimeException;

/**
 * Shopify Billing (recurring app subscriptions).
 *
 * Flow:
 *   1. subscribe()  → returns Shopify's confirmationUrl; merchant approves there
 *   2. Shopify redirects back to /api/billing/callback?charge_id=…
 *   3. activate()   → verifies the charge is ACTIVE and records it locally
 *
 * Free plans never touch Shopify — they're recorded directly.
 *
 * @see https://shopify.dev/docs/apps/launch/billing/subscription-billing
 */
class ShopifyBilling
{
    private const CREATE_MUTATION = <<<'GQL'
    mutation appSubscriptionCreate(
      $name: String!, $returnUrl: URL!, $test: Boolean,
      $lineItems: [AppSubscriptionLineItemInput!]!
    ) {
      appSubscriptionCreate(
        name: $name, returnUrl: $returnUrl, test: $test, lineItems: $lineItems
      ) {
        confirmationUrl
        appSubscription { id status }
        userErrors { field message }
      }
    }
    GQL;

    private const CURRENT_QUERY = <<<'GQL'
    {
      currentAppInstallation {
        activeSubscriptions { id name status test createdAt }
      }
    }
    GQL;

    private const CANCEL_MUTATION = <<<'GQL'
    mutation appSubscriptionCancel($id: ID!) {
      appSubscriptionCancel(id: $id) {
        appSubscription { id status }
        userErrors { field message }
      }
    }
    GQL;

    /**
     * Start a subscription. Returns the Shopify confirmation URL the merchant
     * must visit, or null when the plan is free (activated immediately).
     */
    public function subscribe(Shop $shop, Plan $plan): ?string
    {
        if ((float) $plan->price <= 0) {
            $this->record($shop, $plan, 'active', null);

            return null;
        }

        $returnUrl = rtrim(ShopifyOAuth::appUrl(), '/')
            . '/api/billing/callback?shop=' . urlencode($shop->shopify_domain)
            . '&plan=' . urlencode($plan->code);

        $client = new ShopifyClient($shop);
        $body = $client->graphql(self::CREATE_MUTATION, [
            'name' => $plan->name,
            'returnUrl' => $returnUrl,
            'test' => (bool) config('services.shopify.billing_test', false),
            'lineItems' => [[
                'plan' => [
                    'appRecurringPricingDetails' => [
                        'price' => ['amount' => (float) $plan->price, 'currencyCode' => 'USD'],
                        'interval' => $plan->interval === 'yearly' ? 'ANNUAL' : 'EVERY_30_DAYS',
                    ],
                ],
            ]],
        ]);

        $errors = $client->userErrors($body, 'appSubscriptionCreate');
        if (empty($errors)) {
            $errors = $client->topLevelErrors($body);
        }
        if (! empty($errors)) {
            throw new RuntimeException('Billing failed: ' . json_encode($errors));
        }

        $url = data_get($body, 'data.appSubscriptionCreate.confirmationUrl');
        if (! $url) {
            throw new RuntimeException('Shopify returned no confirmation URL');
        }

        // Pending until the merchant approves on Shopify's screen.
        $this->record($shop, $plan, 'pending', data_get($body, 'data.appSubscriptionCreate.appSubscription.id'));

        return $url;
    }

    /**
     * Called when Shopify redirects back. Confirms the subscription is really
     * ACTIVE on Shopify's side before granting the plan.
     */
    public function activate(Shop $shop, Plan $plan): bool
    {
        $client = new ShopifyClient($shop);
        $body = $client->graphql(self::CURRENT_QUERY);

        $active = collect(data_get($body, 'data.currentAppInstallation.activeSubscriptions', []))
            ->firstWhere('status', 'ACTIVE');

        if (! $active) {
            return false;
        }

        // Supersede any other subscription for this shop.
        Subscription::where('shop_id', $shop->id)
            ->where('status', 'active')
            ->update(['status' => 'cancelled', 'ends_at' => now()]);

        $this->record($shop, $plan, 'active', $active['id']);

        return true;
    }

    /** Cancel the shop's active Shopify subscription and drop back to Starter. */
    public function cancel(Shop $shop): void
    {
        $sub = Subscription::where('shop_id', $shop->id)->where('status', 'active')->latest()->first();

        if ($sub?->shopify_charge_id) {
            try {
                (new ShopifyClient($shop))->graphql(self::CANCEL_MUTATION, ['id' => $sub->shopify_charge_id]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $sub?->update(['status' => 'cancelled', 'ends_at' => now()]);
    }

    private function record(Shop $shop, Plan $plan, string $status, ?string $chargeId): Subscription
    {
        if ($status === 'active') {
            Subscription::where('shop_id', $shop->id)
                ->where('status', 'active')
                ->update(['status' => 'cancelled', 'ends_at' => now()]);
        }

        return Subscription::updateOrCreate(
            ['shop_id' => $shop->id, 'plan_id' => $plan->id, 'status' => $status],
            [
                'shopify_charge_id' => $chargeId,
                'started_at' => $status === 'active' ? now() : null,
                'ends_at' => null,
            ],
        );
    }
}
