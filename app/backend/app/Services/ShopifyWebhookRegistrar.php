<?php

namespace App\Services;

use App\Models\Shop;
use Illuminate\Support\Facades\Log;

/**
 * Keeps a shop's webhook subscriptions pointing at the CURRENT app URL.
 *
 * The old implementation POSTed to the REST webhooks.json endpoint and ignored
 * the response. When a subscription already existed (e.g. registered against a
 * previous tunnel/domain) Shopify replies 422 "address has already been taken",
 * so the stale subscription survived and every delivery failed with
 * WEBHOOK_DELIVERY errors. This version reconciles instead: it looks the
 * subscription up and UPDATES it when the callback URL has changed.
 */
class ShopifyWebhookRegistrar
{
    /** topic => route path */
    private const TOPICS = [
        'APP_UNINSTALLED' => '/api/webhooks/app-uninstalled',
        'CUSTOMERS_DATA_REQUEST' => '/api/webhooks/customers-data-request',
        'CUSTOMERS_REDACT' => '/api/webhooks/customers-redact',
        'SHOP_REDACT' => '/api/webhooks/shop-redact',
    ];

    private const LIST_QUERY = <<<'GQL'
    query($topics: [WebhookSubscriptionTopic!]) {
      webhookSubscriptions(first: 50, topics: $topics) {
        edges {
          node {
            id
            topic
            endpoint { __typename ... on WebhookHttpEndpoint { callbackUrl } }
          }
        }
      }
    }
    GQL;

    private const CREATE_MUTATION = <<<'GQL'
    mutation($topic: WebhookSubscriptionTopic!, $sub: WebhookSubscriptionInput!) {
      webhookSubscriptionCreate(topic: $topic, webhookSubscription: $sub) {
        webhookSubscription { id }
        userErrors { field message }
      }
    }
    GQL;

    private const UPDATE_MUTATION = <<<'GQL'
    mutation($id: ID!, $sub: WebhookSubscriptionInput!) {
      webhookSubscriptionUpdate(id: $id, webhookSubscription: $sub) {
        webhookSubscription { id }
        userErrors { field message }
      }
    }
    GQL;

    /**
     * Ensure every topic is subscribed at the current app URL.
     *
     * @return array<string,string> topic => outcome (created|updated|ok|error: …)
     */
    public function sync(Shop $shop): array
    {
        $client = new ShopifyClient($shop);
        $base = rtrim(ShopifyOAuth::appUrl(), '/');
        $results = [];

        try {
            $existing = $this->existing($client);
        } catch (\Throwable $e) {
            Log::warning('Webhook list failed', ['shop' => $shop->shopify_domain, 'error' => $e->getMessage()]);
            $existing = [];
        }

        foreach (self::TOPICS as $topic => $path) {
            $callbackUrl = $base . $path;

            try {
                $current = $existing[$topic] ?? null;

                if ($current && $current['callbackUrl'] === $callbackUrl) {
                    $results[$topic] = 'ok';
                    continue;
                }

                if ($current) {
                    // Stale URL (old tunnel/domain) — repoint it.
                    $body = $client->graphql(self::UPDATE_MUTATION, [
                        'id' => $current['id'],
                        'sub' => ['callbackUrl' => $callbackUrl, 'format' => 'JSON'],
                    ]);
                    $errors = $client->userErrors($body, 'webhookSubscriptionUpdate');
                    $results[$topic] = $errors ? 'error: ' . json_encode($errors) : 'updated';
                } else {
                    $body = $client->graphql(self::CREATE_MUTATION, [
                        'topic' => $topic,
                        'sub' => ['callbackUrl' => $callbackUrl, 'format' => 'JSON'],
                    ]);
                    $errors = $client->userErrors($body, 'webhookSubscriptionCreate');
                    $results[$topic] = $errors ? 'error: ' . json_encode($errors) : 'created';
                }
            } catch (\Throwable $e) {
                $results[$topic] = 'error: ' . $e->getMessage();
            }
        }

        $problems = array_filter($results, fn ($r) => str_starts_with($r, 'error'));
        if ($problems) {
            Log::warning('Webhook sync problems', [
                'shop' => $shop->shopify_domain,
                'results' => $problems,
            ]);
        }

        return $results;
    }

    /** @return array<string,array{id:string,callbackUrl:?string}> keyed by topic */
    private function existing(ShopifyClient $client): array
    {
        $body = $client->graphql(self::LIST_QUERY, ['topics' => array_keys(self::TOPICS)]);

        $out = [];
        foreach (data_get($body, 'data.webhookSubscriptions.edges', []) as $edge) {
            $node = $edge['node'] ?? [];
            if (! isset($node['topic'])) {
                continue;
            }
            $out[$node['topic']] = [
                'id' => $node['id'],
                'callbackUrl' => data_get($node, 'endpoint.callbackUrl'),
            ];
        }

        return $out;
    }
}
