<?php

namespace Tests\Feature;

use App\Services\SessionToken;
use Tests\TestCase;

class SessionTokenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.shopify.api_key' => 'test_key',
            'services.shopify.api_secret' => 'test_secret',
        ]);
    }

    private function makeToken(array $overrides = []): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = array_merge([
            'iss' => 'https://demo.myshopify.com/admin',
            'dest' => 'https://demo.myshopify.com',
            'aud' => 'test_key',
            'exp' => time() + 60,
            'nbf' => time() - 10,
        ], $overrides);

        $b64 = fn ($d) => rtrim(strtr(base64_encode(json_encode($d)), '+/', '-_'), '=');
        $signingInput = $b64($header) . '.' . $b64($payload);
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $signingInput, 'test_secret', true)), '+/', '-_'), '=');

        return "{$signingInput}.{$sig}";
    }

    public function test_valid_token_returns_shop_domain(): void
    {
        $this->assertSame('demo.myshopify.com', SessionToken::shopDomain($this->makeToken()));
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $token = $this->makeToken() . 'x';
        $this->assertNull(SessionToken::shopDomain($token));
    }

    public function test_expired_token_is_rejected(): void
    {
        $this->assertNull(SessionToken::shopDomain($this->makeToken(['exp' => time() - 100])));
    }

    public function test_wrong_audience_is_rejected(): void
    {
        $this->assertNull(SessionToken::shopDomain($this->makeToken(['aud' => 'someone_else'])));
    }
}
