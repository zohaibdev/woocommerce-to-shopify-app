<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing becomes auditable and self-consistent.
 *
 * subscriptions — one row per Shopify AppSubscription (plus legacy local-only
 * rows). New columns are a SNAPSHOT of what Shopify reported for that charge, so
 * history keeps showing what the merchant actually paid after an admin edits a
 * plan's price. Nothing here is derived or estimated.
 *
 *   shopify_status        last AppSubscriptionStatus seen (ACTIVE, PENDING, …)
 *   shopify_updated_at    when Shopify last changed it (webhook updated_at) —
 *                         orders out-of-sequence webhook deliveries
 *   shopify_created_at    AppSubscription.createdAt
 *   price/currency/interval, test, trial_days — from the charge itself
 *   approved_at           the merchant came back through the signed return URL
 *   previous_subscription_id  the subscription this one replaced (plan changes)
 *   confirmation_url      reused for repeat clicks instead of a new charge
 *   active_shop_id        generated: shop_id while status = 'active', else NULL.
 *                         UNIQUE — the database itself refuses a second active
 *                         subscription for a shop, whatever the code does.
 *
 * status becomes a string so 'declined' can be stored as itself; it used to be
 * 'cancelled' with cancellation_reason = 'declined'.
 *
 * billing_events — append-only lifecycle history and audit log.
 * shops.billing_synced_at — last successful reconcile with Shopify.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->change();
        });

        // Declines were stored as cancelled + reason before 'declined' existed.
        DB::table('subscriptions')
            ->where('status', 'cancelled')
            ->where('cancellation_reason', 'declined')
            ->update(['status' => 'declined']);

        // At most one active row per shop, BEFORE the unique index can exist.
        // Keep the newest; older duplicates are what the old code's supersede
        // step should already have ended.
        DB::table('subscriptions')
            ->where('status', 'active')
            ->select('shop_id')
            ->groupBy('shop_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('shop_id')
            ->each(function ($shopId) {
                $keep = DB::table('subscriptions')->where('shop_id', $shopId)->where('status', 'active')->max('id');

                DB::table('subscriptions')
                    ->where('shop_id', $shopId)
                    ->where('status', 'active')
                    ->where('id', '<>', $keep)
                    ->update([
                        'status' => 'cancelled',
                        'cancellation_reason' => 'replaced',
                        'cancelled_at' => now(),
                        'ends_at' => now(),
                    ]);
            });

        // One row per Shopify charge id: the newest keeps it.
        DB::table('subscriptions')
            ->whereNotNull('shopify_charge_id')
            ->select('shopify_charge_id')
            ->groupBy('shopify_charge_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('shopify_charge_id')
            ->each(function ($chargeId) {
                $keep = DB::table('subscriptions')->where('shopify_charge_id', $chargeId)->max('id');

                DB::table('subscriptions')
                    ->where('shopify_charge_id', $chargeId)
                    ->where('id', '<>', $keep)
                    ->update(['shopify_charge_id' => null]);
            });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('shopify_status', 20)->nullable()->after('shopify_charge_id');
            $table->timestamp('shopify_updated_at')->nullable()->after('shopify_status');
            $table->timestamp('shopify_created_at')->nullable()->after('shopify_updated_at');
            $table->decimal('price', 10, 2)->nullable()->after('plan_id');
            $table->string('currency', 3)->nullable()->after('price');
            $table->string('interval', 20)->nullable()->after('currency');
            $table->boolean('test')->default(false)->after('interval');
            $table->unsignedInteger('trial_days')->nullable()->after('test');
            $table->timestamp('approved_at')->nullable()->after('started_at');
            $table->foreignId('previous_subscription_id')->nullable()->after('shop_id')
                ->constrained('subscriptions')->nullOnDelete();
            $table->text('confirmation_url')->nullable();

            $table->unique('shopify_charge_id');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('active_shop_id')->nullable()
                ->virtualAs("CASE WHEN status = 'active' THEN shop_id END");
            $table->unique('active_shop_id');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->timestamp('billing_synced_at')->nullable();
        });

        Schema::create('billing_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 40);
            // merchant | admin | callback | webhook | sync | system
            $table->string('source', 20);
            // Local subscription status right after the event, when there is one.
            $table->string('status', 20)->nullable();
            $table->string('shopify_subscription_id')->nullable();
            $table->string('message', 500)->nullable();
            $table->json('context')->nullable();
            // When it HAPPENED — Shopify's updated_at for webhooks, else when we
            // observed or performed it. Never estimated.
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['shop_id', 'occurred_at']);
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_events');

        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('billing_synced_at');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropUnique(['active_shop_id']);
            $table->dropColumn('active_shop_id');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropUnique(['shopify_charge_id']);
            $table->dropConstrainedForeignId('previous_subscription_id');
            $table->dropColumn([
                'shopify_status', 'shopify_updated_at', 'shopify_created_at', 'price', 'currency',
                'interval', 'test', 'trial_days', 'approved_at', 'confirmation_url',
            ]);
        });

        DB::table('subscriptions')->where('status', 'declined')->update([
            'status' => 'cancelled',
            'cancellation_reason' => 'declined',
        ]);
    }
};
