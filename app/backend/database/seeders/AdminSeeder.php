<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\Plan;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        AdminUser::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@storeshift.app')],
            [
                'name' => env('ADMIN_NAME', 'Super Admin'),
                'password' => env('ADMIN_PASSWORD', 'ChangeMe123!'),
                'is_super' => true,
            ],
        );

        // `null` in a limit means unlimited, `0` means the entity is not included
        // at all, and `total` is a SHARED record budget spent across products,
        // customers and orders together (see App\Support\PlanPool) rather than a
        // separate cap for each.
        $plans = [
            [
                'code' => 'starter',
                'name' => 'Starter',
                'price' => 0,
                'limits' => [
                    'products' => 10,
                    'customers' => 10,
                    'orders' => 0,
                    'metafields' => false,
                    // Content features are what the paid tiers are for. 0 means
                    // "not in this plan": the wizard disables the entity and says
                    // why, instead of letting a free shop migrate them and only
                    // finding out afterwards.
                    'coupons' => 0,
                    'blogs' => 0,
                    'reviews' => 0,
                ],
            ],
            [
                'code' => 'basic',
                'name' => 'Basic',
                'price' => 19,
                'limits' => [
                    'products' => 2000,
                    'customers' => 2000,
                    'orders' => 1000,
                    'metafields' => true,
                    'coupons' => null,
                    'blogs' => null,
                    'reviews' => null,
                ],
            ],
            [
                'code' => 'pro',
                'name' => 'Pro',
                'price' => 49,
                'limits' => [
                    // ONE budget of 20,000 records, spent however the merchant
                    // likes across the three metered types — not 20,000 of each.
                    // Three separate caps made the headline number meaningless:
                    // a store with 20,000 products and a store with 60,000
                    // records across all three bought the same plan.
                    'total' => 20000,
                    'metafields' => true,
                    'coupons' => null,
                    'blogs' => null,
                    'reviews' => null,
                ],
            ],
            [
                'code' => 'enterprise',
                'name' => 'Enterprise',
                'price' => 149,
                'limits' => [
                    'products' => null,
                    'customers' => null,
                    'orders' => null,
                    'metafields' => true,
                    'coupons' => null,
                    'blogs' => null,
                    'reviews' => null,
                    // Negotiated and invoiced off-platform, so the app must not
                    // offer a Shopify charge screen for it — the checkout could
                    // not deliver what the plan actually is.
                    'contact_only' => true,
                ],
            ],
        ];

        // These are STARTING values for a plan that does not exist yet, not the
        // source of truth for one that does.
        //
        // This used to be updateOrCreate, and the deploy runbook runs the seeder
        // on every release — so every price and every limit an admin had set at
        // /app/admin/plans was silently reset to whatever was hard-coded here, on
        // a deploy that had nothing to do with pricing. Merchants on a raised cap
        // quietly lost it, and nothing in the app said so. The plans table is
        // meant to be editable without a deploy; now it actually is.
        //
        // Set PLAN_SEED_OVERWRITE=true for the one case where resetting really is
        // what you want: pushing a deliberate pricing change out from the code.
        $overwrite = filter_var(env('PLAN_SEED_OVERWRITE', false), FILTER_VALIDATE_BOOLEAN);

        foreach ($plans as $p) {
            $attributes = $p + ['interval' => 'monthly', 'active' => true];

            $existing = Plan::where('code', $p['code'])->first();

            if (! $existing) {
                Plan::create($attributes);

                continue;
            }

            if ($overwrite) {
                $existing->update($attributes);
            }
        }

        // Retire the old free plan code if it's still around.
        Plan::where('code', 'free')->update(['active' => false]);
    }
}
