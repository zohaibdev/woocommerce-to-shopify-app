<?php

namespace Tests\Unit;

use App\Services\ShopifyClient;
use Tests\TestCase;

class ShopifyErrorNormalisationTest extends TestCase
{
    public function test_string_errors_are_wrapped_into_a_list(): void
    {
        // Shopify returns this shape on some permission failures.
        $out = ShopifyClient::normalizeErrors('[API] This action requires merchant approval.');

        $this->assertCount(1, $out);
        $this->assertSame('[API] This action requires merchant approval.', $out[0]['message']);
    }

    public function test_list_of_error_objects_passes_through(): void
    {
        $errors = [
            ['message' => 'a', 'extensions' => ['code' => 'ACCESS_DENIED']],
            ['message' => 'b'],
        ];

        $this->assertSame($errors, ShopifyClient::normalizeErrors($errors));
    }

    public function test_single_error_object_is_wrapped(): void
    {
        $out = ShopifyClient::normalizeErrors(['message' => 'solo']);

        $this->assertCount(1, $out);
        $this->assertSame('solo', $out[0]['message']);
    }

    public function test_empty_and_invalid_shapes_are_safe(): void
    {
        $this->assertSame([], ShopifyClient::normalizeErrors([]));
        $this->assertSame([], ShopifyClient::normalizeErrors(''));
        $this->assertSame([], ShopifyClient::normalizeErrors(null));
    }
}
