<?php

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The marketing site's pricing table used to hardcode its own copy of every
 * plan's numbers, so an admin editing coupons/blogs/reviews limits (or the
 * price) in AdminPlans had no way to reach it. GET /api/plans is the public,
 * unauthenticated feed that lets the static page render the real thing.
 */
class PublicPlansEndpointTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_returns_active_plans_with_their_content_feature_limits_and_no_auth(): void
    {
        Plan::create([
            'code' => 'basic',
            'name' => 'Basic',
            'price' => 19,
            'interval' => 'monthly',
            'active' => true,
            'limits' => ['products' => 2000, 'customers' => 2000, 'orders' => 1000, 'coupons' => null, 'blogs' => null, 'reviews' => null],
        ]);
        Plan::create([
            'code' => 'hidden',
            'name' => 'Hidden',
            'price' => 999,
            'interval' => 'monthly',
            'active' => false,
            'limits' => [],
        ]);

        // No session token, no X-Shop-Domain header — this must work unauthenticated.
        $response = $this->getJson('/api/plans');

        $response->assertOk();
        $codes = collect($response->json('plans'))->pluck('code');

        $this->assertTrue($codes->contains('basic'));
        $this->assertFalse($codes->contains('hidden'), 'inactive plans must not be offered publicly');

        $basic = collect($response->json('plans'))->firstWhere('code', 'basic');
        $this->assertNull($basic['limits']['coupons']);
        $this->assertNull($basic['limits']['blogs']);
        $this->assertNull($basic['limits']['reviews']);
        $this->assertArrayNotHasKey('current_plan', $response->json());
    }

    /** @test */
    public function a_plan_that_excludes_a_content_feature_reports_it_as_zero(): void
    {
        Plan::create([
            'code' => 'starter',
            'name' => 'Starter',
            'price' => 0,
            'interval' => 'monthly',
            'active' => true,
            'limits' => ['products' => 10, 'customers' => 10, 'orders' => 0, 'coupons' => 0, 'blogs' => 0, 'reviews' => 0],
        ]);

        $response = $this->getJson('/api/plans');

        $starter = collect($response->json('plans'))->firstWhere('code', 'starter');
        $this->assertSame(0, $starter['limits']['coupons']);
        $this->assertSame(0, $starter['limits']['blogs']);
        $this->assertSame(0, $starter['limits']['reviews']);
    }
}
