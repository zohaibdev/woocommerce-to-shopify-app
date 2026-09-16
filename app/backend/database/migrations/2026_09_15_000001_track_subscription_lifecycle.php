<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happened to a subscription, not just its latest status.
 *
 * A cancelled row is kept with why and when it ended, so a reinstalled store can
 * be told "your Basic subscription was cancelled when the app was uninstalled"
 * instead of the plan silently vanishing (App Store review 1.2.2).
 * current_period_end is the renewal date, captured while the charge is active —
 * Shopify returns it as null once a charge is inactive.
 *
 * Comment-only edit after the first deploy: this migration briefly described a
 * paid grace period, which was removed. Cancellation is immediate; ends_at is the
 * moment the subscription stopped granting access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('current_period_end')->nullable()->after('started_at');
            $table->timestamp('cancelled_at')->nullable()->after('current_period_end');
            // uninstalled | merchant | replaced | declined | shopify | expired | frozen
            $table->string('cancellation_reason', 32)->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['current_period_end', 'cancelled_at', 'cancellation_reason']);
        });
    }
};
