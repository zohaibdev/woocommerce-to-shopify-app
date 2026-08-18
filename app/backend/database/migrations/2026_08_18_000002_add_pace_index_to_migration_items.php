<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The live ETA measures each entity's pace from its own mapping rows, which is
 * `migration_id + entity_type` filtered and grouped by `created_at`.
 *
 * The existing (migration_id, entity_type, status) index answers the first two
 * columns and then leaves the date filter to be applied by reading every mapping
 * row the run has — on a 50,000-record migration, on a page that polls every
 * three seconds. Putting created_at in the index turns the rolling window into a
 * range scan over just the window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migration_items', function (Blueprint $table) {
            $table->index(['migration_id', 'entity_type', 'created_at'], 'migration_items_pace_index');
        });
    }

    public function down(): void
    {
        Schema::table('migration_items', function (Blueprint $table) {
            $table->dropIndex('migration_items_pace_index');
        });
    }
};
