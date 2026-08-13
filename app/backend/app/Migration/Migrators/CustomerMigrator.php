<?php

namespace App\Migration\Migrators;

use App\Migration\AbstractMigrator;
use App\Migration\Concerns\ShedsRejectedFields;
use App\Migration\MigrationItemResult;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use App\Services\WooCommerceClient;
use App\Support\PhoneNumber;

class CustomerMigrator extends AbstractMigrator
{
    use ShedsRejectedFields;

    private const CREATE_MUTATION = <<<'GQL'
    mutation customerCreate($input: CustomerInput!) {
      customerCreate(input: $input) {
        customer { id }
        userErrors { field message }
      }
    }
    GQL;

    // customer tags are applied from the migration's custom_tags option.

    public function entityType(): string
    {
        return 'customer';
    }

    public function count(WoocommerceConnection $connection): int
    {
        return (new WooCommerceClient($connection))->total('customers');
    }

    public function fetchBatch(WoocommerceConnection $connection, int $page, int $perPage): array
    {
        return (new WooCommerceClient($connection))->list('customers', $page, $perPage);
    }

    protected function doMigrate(StoreMigration $migration, array $wooItem, ?string $existingShopifyId = null): MigrationItemResult
    {
        $billing = $wooItem['billing'] ?? [];

        // WooCommerce keeps whatever the shopper typed ("0300 1234567"); Shopify
        // needs E.164. The billing country supplies the missing calling code.
        $phone = PhoneNumber::toE164($billing['phone'] ?? null, $billing['country'] ?? null);

        $input = [
            'firstName' => $wooItem['first_name'] ?? ($billing['first_name'] ?? null),
            'lastName' => $wooItem['last_name'] ?? ($billing['last_name'] ?? null),
            'email' => $wooItem['email'] ?? ($billing['email'] ?? null),
            'phone' => $phone,
            // NOTE: passwords are NOT migrated (hashes incompatible). Invites are Phase 2.
            'addresses' => $this->addresses($wooItem),
        ];

        // Apply the merchant's custom tags for this migration.
        $tags = $migration->customTags();
        if (! empty($tags)) {
            $input['tags'] = $tags;
        }

        if (empty($input['email'])) {
            return MigrationItemResult::failed('Customer has no email address');
        }

        // A phone Shopify won't take ("Phone is invalid", "Phone has already been
        // taken") or an address it rejects must not cost us the person. Email
        // stays required — it is how the customer is identified.
        return $this->writeSheddingBadFields(
            fn (array $attempt) => $this->create($migration, $attempt),
            $input,
            ['phone', 'addresses', 'tags'],
        );
    }

    /** @return array{0: string|null, 1: array} [shopifyId, errors] */
    private function create(StoreMigration $migration, array $input): array
    {
        $shopify = $this->shopify($migration);
        $body = $shopify->graphql(self::CREATE_MUTATION, ['input' => $input]);

        $errors = $shopify->userErrors($body, 'customerCreate');

        // Surface top-level errors too (e.g. Protected Customer Data not approved).
        if (empty($errors)) {
            $errors = $shopify->topLevelErrors($body);
        }

        return [data_get($body, 'data.customerCreate.customer.id'), $errors];
    }


    private function addresses(array $woo): array
    {
        $b = $woo['billing'] ?? [];
        if (empty($b['address_1'])) {
            return [];
        }

        return [[
            'address1' => $b['address_1'] ?? null,
            'address2' => $b['address_2'] ?? null,
            'city' => $b['city'] ?? null,
            'provinceCode' => $b['state'] ?? null,
            'countryCode' => $b['country'] ?? null,
            'zip' => $b['postcode'] ?? null,
            'phone' => PhoneNumber::toE164($b['phone'] ?? null, $b['country'] ?? null),
        ]];
    }
}
