<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            // Merchant-facing plan name ("Shopify Plus"), shown in migration logs.
            $table->string('shopify_plan')->nullable()->after('scopes');

            // The shop's REAL GraphQL budget, learned from throttleStatus on every
            // response. Migration concurrency is derived from these, so a Plus
            // store is not paced like a Standard one.
            $table->unsignedInteger('api_bucket_size')->nullable()->after('shopify_plan');
            $table->unsignedInteger('api_restore_rate')->nullable()->after('api_bucket_size');
            $table->timestamp('limits_checked_at')->nullable()->after('api_restore_rate');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn([
                'shopify_plan',
                'api_bucket_size',
                'api_restore_rate',
                'limits_checked_at',
            ]);
        });
    }
};
