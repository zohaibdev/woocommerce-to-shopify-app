<?php

namespace Tests\Feature;

use App\Migration\Migrators\CustomerMigrator;
use App\Models\MigrationItem;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * WooCommerce stores whatever the shopper typed; Shopify wants E.164 and rejects
 * anything else with "Phone is invalid" — which used to fail the entire customer.
 * The billing country supplies the calling code the number is missing.
 */
class PhoneNumberTest extends TestCase
{
    use RefreshDatabase;

    public static function numbers(): array
    {
        return [
            'pakistani mobile with trunk zero' => ['0300 1234567', 'PK', '+923001234567'],
            'pakistani landline with brackets' => ['(021) 111 222 333', 'PK', '+9221111222333'],
            'already international' => ['+92 300 1234567', 'PK', '+923001234567'],
            'double-zero international prefix' => ['0092 300 1234567', 'PK', '+923001234567'],
            'calling code without plus' => ['923001234567', 'PK', '+923001234567'],
            'us ten digit' => ['310-555-1234', 'US', '+13105551234'],
            'us with long distance 1' => ['1 (310) 555-1234', 'US', '+13105551234'],
            'canada' => ['416-555-0123', 'CA', '+14165550123'],
            'uk trunk zero' => ['07911 123456', 'GB', '+447911123456'],
            'germany' => ['0151 12345678', 'DE', '+4915112345678'],
            'france' => ['06 12 34 56 78', 'FR', '+33612345678'],
            'australia' => ['0412 345 678', 'AU', '+61412345678'],
            'uae' => ['050 123 4567', 'AE', '+971501234567'],
            'india spaced' => ['98765 43210', 'IN', '+919876543210'],
            'lowercase country still works' => ['0300 1234567', 'pk', '+923001234567'],

            // Trunk prefixes that are not "0" — these silently produced wrong
            // numbers before, which is worse than producing none.
            'russia trunk eight' => ['8 912 345 6789', 'RU', '+79123456789'],
            'kazakhstan trunk eight' => ['8 701 234 5678', 'KZ', '+77012345678'],
            'lithuania trunk eight' => ['8 612 34567', 'LT', '+37061234567'],
            'hungary two digit trunk' => ['06 20 123 4567', 'HU', '+36201234567'],

            // Extensions must be cut, not concatenated onto the subscriber number.
            'extension written as ext' => ['310-555-1234 ext 22', 'US', '+13105551234'],
            'extension written as x' => ['310 555 1234 x99', 'US', '+13105551234'],
            'extension after a comma' => ['310 555 1234, 5', 'US', '+13105551234'],
        ];
    }

    #[DataProvider('numbers')]
    public function test_it_normalises_to_e164(string $raw, string $country, string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::toE164($raw, $country));
    }

    public static function unusable(): array
    {
        return [
            'empty' => ['', 'PK'],
            'whitespace' => ['   ', 'PK'],
            'no digits' => ['n/a', 'PK'],
            'too short to reach anyone' => ['123', 'PK'],
            'unknown country' => ['0300 1234567', 'ZZ'],
            'missing country' => ['0300 1234567', null],
            'too long for e164' => ['0300123456789012345', 'PK'],
        ];
    }

    #[DataProvider('unusable')]
    public function test_it_returns_null_rather_than_inventing_a_number(?string $raw, ?string $country): void
    {
        $this->assertNull(PhoneNumber::toE164($raw, $country));
    }

    public function test_italy_keeps_its_leading_zero(): void
    {
        // Italy is the exception: the leading 0 is part of the subscriber number.
        $this->assertSame('+39061234567', PhoneNumber::toE164('06 1234567', 'IT'));
    }

    // ---------------------------------------------------------------------
    // A phone Shopify still won't take must not cost us the customer
    // ---------------------------------------------------------------------

    public function test_a_rejected_phone_drops_the_phone_not_the_customer(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            return Http::response(['data' => ['customerCreate' => ++$attempts === 1
                ? ['customer' => null, 'userErrors' => [['field' => ['phone'], 'message' => 'Phone is invalid']]]
                : ['customer' => ['id' => 'gid://shopify/Customer/9'], 'userErrors' => []]]], 200);
        });

        $result = (new CustomerMigrator())->migrateItem($this->migration(), [
            'id' => 7,
            'email' => 'buyer@example.com',
            'billing' => ['phone' => '0300 1234567', 'country' => 'PK', 'address_1' => '1 Main St'],
        ]);

        $this->assertSame('success', $result->status, 'a bad phone must not fail the customer');
        $this->assertSame('gid://shopify/Customer/9', $result->shopifyId);
        $this->assertNotNull($result->warning);

        // The retry must actually have dropped it, not sent the same value again.
        Http::assertSent(fn ($req) => data_get(json_decode($req->body(), true), 'variables.input.phone') === null);
    }

    public function test_a_duplicate_phone_also_drops_the_phone(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            return Http::response(['data' => ['customerCreate' => ++$attempts === 1
                ? ['customer' => null, 'userErrors' => [['field' => ['phone'], 'message' => 'Phone has already been taken']]]
                : ['customer' => ['id' => 'gid://shopify/Customer/10'], 'userErrors' => []]]], 200);
        });

        $result = (new CustomerMigrator())->migrateItem($this->migration(), [
            'id' => 8,
            'email' => 'second@example.com',
            'billing' => ['phone' => '0300 1234567', 'country' => 'PK'],
        ]);

        $this->assertSame('success', $result->status);
    }

    public function test_a_non_phone_error_still_fails_the_customer(): void
    {
        Http::fake([
            '*/graphql.json' => Http::response([
                'data' => ['customerCreate' => ['customer' => null, 'userErrors' => [
                    ['field' => ['email'], 'message' => 'Email has already been taken'],
                ]]],
            ], 200),
        ]);

        $result = (new CustomerMigrator())->migrateItem($this->migration(), [
            'id' => 9,
            'email' => 'dupe@example.com',
            'billing' => ['phone' => '0300 1234567', 'country' => 'PK'],
        ]);

        $this->assertSame('failed', $result->status, 'only phone errors are forgiven');
        $this->assertSame(0, MigrationItem::where('woocommerce_id', 9)->where('status', 'success')->count());
    }

    private function migration(): StoreMigration
    {
        $shop = Shop::create([
            'shopify_domain' => 'phones.myshopify.com',
            'access_token' => 'shpat_test',
            'token_expires_at' => now()->addHour(),
        ]);

        $connection = WoocommerceConnection::create([
            'shop_id' => $shop->id,
            'store_url' => 'https://woo.example.com',
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
            'status' => 'connected',
        ]);

        $migration = StoreMigration::create([
            'shop_id' => $shop->id,
            'woocommerce_connection_id' => $connection->id,
            'status' => 'processing',
            'selected_entities' => ['customer'],
        ]);

        $migration->setRelation('shop', $shop);
        $migration->setRelation('connection', $connection);

        return $migration;
    }
}
