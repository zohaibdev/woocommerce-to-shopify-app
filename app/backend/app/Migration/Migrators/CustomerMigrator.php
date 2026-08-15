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

    /** Recovery lookup after an interrupted write (see findExisting). */
    private const FIND_BY_EMAIL = <<<'GQL'
    query findCustomer($q: String!) {
      customers(first: 1, query: $q) { nodes { id } }
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

    /** Source resource for retry-by-id (see AbstractMigrator::fetchByIds). */
    protected function wooSource(): ?array
    {
        return ['customers', []];
    }

    public function fetchBatch(WoocommerceConnection $connection, int $page, int $perPage): array
    {
        return (new WooCommerceClient($connection))->list('customers', $page, $perPage);
    }

    /**
     * Did an interrupted attempt already create this customer? Email is the key
     * Shopify itself treats as unique, so this is exact. Asked only when the
     * mapping shows a write we never got the answer to — see
     * AbstractMigrator::migrateItem.
     */
    protected function findExisting(StoreMigration $migration, array $wooItem): ?string
    {
        $email = trim((string) ($wooItem['email'] ?? data_get($wooItem, 'billing.email', '')));

        if ($email === '') {
            return null;
        }

        $body = $this->shopify($migration)->graphql(self::FIND_BY_EMAIL, [
            'q' => 'email:"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $email).'"',
        ]);

        return data_get($body, 'data.customers.nodes.0.id');
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


    /**
     * The customer's addresses — billing first, then shipping when it is a
     * different place.
     *
     * Only the billing address used to be migrated, and even that arrived without
     * a name or a company on it. A merchant who ships to an office, or to anyone
     * other than the account holder, lost that address entirely — and Shopify
     * shows the name and company ON the address label, so a nameless address is
     * one somebody has to retype before it can be used.
     */
    private function addresses(array $woo): array
    {
        $addresses = [];

        foreach ([$woo['billing'] ?? [], $woo['shipping'] ?? []] as $source) {
            $address = $this->address($source);

            if ($address !== null) {
                $addresses[] = $address;
            }
        }

        // A store where shipping simply mirrors billing — the common case — would
        // otherwise give every customer the same address twice.
        //
        // The comparison deliberately ignores PHONE. WooCommerce's billing block
        // has a phone field and its shipping block does not, so comparing whole
        // records found a difference on almost every customer alive and the
        // de-duplication never fired once in practice — which is precisely the
        // duplicate it was written to prevent.
        $seen = [];
        $unique = [];

        foreach ($addresses as $address) {
            $signature = serialize(array_diff_key($address, ['phone' => null]));

            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $unique[] = $address;
        }

        return $unique;
    }

    /** One Woo address block as a Shopify MailingAddressInput, or null if empty. */
    private function address(array $a): ?array
    {
        if (empty($a['address_1'])) {
            return null;
        }

        return array_filter([
            // Shown on the address label. Falls back to the account name so an
            // address is never anonymous when we know who it belongs to.
            'firstName' => $a['first_name'] ?? null,
            'lastName' => $a['last_name'] ?? null,
            'company' => $a['company'] ?? null,
            'address1' => $a['address_1'] ?? null,
            'address2' => $a['address_2'] ?? null,
            'city' => $a['city'] ?? null,
            'provinceCode' => $a['state'] ?? null,
            'countryCode' => $a['country'] ?? null,
            'zip' => $a['postcode'] ?? null,
            // Woo's shipping block has no phone; billing's is used where present.
            'phone' => PhoneNumber::toE164($a['phone'] ?? null, $a['country'] ?? null),
        ], fn ($v) => $v !== null && $v !== '');
    }
}
