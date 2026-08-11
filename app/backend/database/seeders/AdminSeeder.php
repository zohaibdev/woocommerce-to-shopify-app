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

        $plans = [
            ['code' => 'free', 'name' => 'Free', 'price' => 0, 'limits' => ['products' => 100, 'customers' => 100, 'orders' => 0]],
            ['code' => 'basic', 'name' => 'Basic', 'price' => 19, 'limits' => ['products' => 2000, 'customers' => 2000, 'orders' => 1000]],
            ['code' => 'pro', 'name' => 'Pro', 'price' => 49, 'limits' => ['products' => 20000, 'customers' => 20000, 'orders' => 20000]],
            ['code' => 'enterprise', 'name' => 'Enterprise', 'price' => 149, 'limits' => ['products' => null, 'customers' => null, 'orders' => null]],
        ];

        foreach ($plans as $p) {
            Plan::firstOrCreate(['code' => $p['code']], $p + ['interval' => 'monthly', 'active' => true]);
        }
    }
}
