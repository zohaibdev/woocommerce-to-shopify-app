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

        // `null` in a limit means unlimited. Orders/metafields are gated per plan.
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
                ],
            ],
            [
                'code' => 'pro',
                'name' => 'Pro',
                'price' => 49,
                'limits' => [
                    'products' => 20000,
                    'customers' => 20000,
                    'orders' => 20000,
                    'metafields' => true,
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
                ],
            ],
        ];

        // updateOrCreate (not firstOrCreate): plan definitions are configuration,
        // so re-seeding must bring existing rows in line with the code.
        foreach ($plans as $p) {
            Plan::updateOrCreate(
                ['code' => $p['code']],
                $p + ['interval' => 'monthly', 'active' => true],
            );
        }

        // Retire the old free plan code if it's still around.
        Plan::where('code', 'free')->update(['active' => false]);
    }
}
