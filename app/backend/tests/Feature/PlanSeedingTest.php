<?php

namespace Tests\Feature;

use App\Models\Plan;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The plans table is documented as editable from /app/admin/plans "with no
 * deploy needed", and the deploy runbook runs this seeder on every release. Both
 * of those cannot be true while the seeder overwrites existing rows — which it
 * did, so every price and cap an admin had set was quietly reset by a release
 * that had nothing to do with pricing.
 */
class PlanSeedingTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function reseeding_keeps_limits_an_admin_changed(): void
    {
        $this->seed(AdminSeeder::class);

        // The admin raises the Pro cap from the admin panel.
        $pro = Plan::where('code', 'pro')->firstOrFail();
        $pro->update([
            'price' => 59,
            'limits' => ['total' => 75000, 'metafields' => true],
        ]);

        // …and then a perfectly ordinary deploy runs the seeder again.
        $this->seed(AdminSeeder::class);

        $pro->refresh();

        $this->assertSame(75000, $pro->limits['total']);
        $this->assertSame('59.00', (string) $pro->price);
    }

    /** @test */
    public function reseeding_still_creates_a_plan_that_is_missing(): void
    {
        $this->seed(AdminSeeder::class);

        Plan::where('code', 'pro')->delete();

        $this->seed(AdminSeeder::class);

        $this->assertNotNull(Plan::where('code', 'pro')->first(), 'a genuinely new plan must still arrive');
    }

    /** @test */
    public function an_explicit_overwrite_pushes_the_code_values_back_out(): void
    {
        $this->seed(AdminSeeder::class);

        Plan::where('code', 'pro')->update(['limits' => ['total' => 1]]);

        // The one case where resetting is what you actually meant.
        putenv('PLAN_SEED_OVERWRITE=true');
        $_ENV['PLAN_SEED_OVERWRITE'] = 'true';

        try {
            $this->seed(AdminSeeder::class);
        } finally {
            putenv('PLAN_SEED_OVERWRITE');
            unset($_ENV['PLAN_SEED_OVERWRITE']);
        }

        $this->assertSame(20000, Plan::where('code', 'pro')->firstOrFail()->limits['total']);
    }
}
