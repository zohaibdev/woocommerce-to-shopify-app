<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a subscription's life looked like, not just its latest status.
 *
 * Shopify returns currentPeriodEnd as NULL once a subscription is no longer
 * active, and an uninstall revokes the token before the app can ask. So the date
 * a merchant has paid up to must be captured WHILE the subscription is active —
 * afterwards nobody can tell us. Without it a reinstall could only say "Free",
 * which is what App Store review 1.2.2 rejected: the app did not reflect that a
 * paid plan had been cancelled, nor how long the merchant still had it for.
 *
 * ends_at keeps its meaning as "when the plan stops granting access". For a
 * cancelled paid plan that is now the end of the paid period, not the moment of
 * cancellation — cancelled_at records the moment.
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
