<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two changes a large catalogue makes unavoidable.
 *
 * 1. `migration_logs.message` was a VARCHAR(255). A Shopify userError set encoded
 *    as JSON goes past that routinely, and under MySQL's strict mode the INSERT
 *    THROWS — inside the item loop, outside its try/catch, so one long error
 *    message killed the whole batch job. SQLite ignores varchar lengths, which is
 *    exactly why every test passed while production broke on real merchant data.
 *
 * 2. `migration_items.attempts` records that we have already TRIED to write this
 *    record to Shopify. Without it, a worker killed between the Shopify write and
 *    the mapping update looks identical to an item that was never started, and
 *    the resume creates the product a second time.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('migration_logs', function (Blueprint $table) {
            $table->text('message')->change();
        });

        Schema::table('migration_items', function (Blueprint $table) {
            $table->unsignedInteger('attempts')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('migration_logs', function (Blueprint $table) {
            $table->string('message')->change();
        });

        Schema::table('migration_items', function (Blueprint $table) {
            $table->dropColumn('attempts');
        });
    }
};
