<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns id-pinning into a phase of its own, so a chunk's WooCommerce ids are
 * decided in ONE pass at the start of the run instead of whenever that chunk
 * first happens to be worked.
 *
 * WHY IT MATTERS. A chunk used to be addressed by page number until its first
 * job ran, and only then pinned to concrete ids. Page/offset paging is not
 * stable across a DELETE: remove one record and every page after it shifts one
 * place left, so the records sitting on the boundary between "already pinned"
 * and "not pinned yet" are skipped. They are never fetched, so they have no row
 * in migration_items at all — invisible to the counters, to the failed-items
 * list and to "Retry failed items". A run could quietly deliver 19,950 of 20,000
 * products and call itself finished.
 *
 * The exposure was the WHOLE RUN — hours, during which a merchant tidying their
 * catalogue is entirely normal. Pinning every chunk up front collapses it to the
 * length of the planning pass, which is one cheap `_fields=id` request per
 * hundred records: minutes, not hours. Planning is resumable and time-bounded
 * like everything else, and it hands work to the runner as it goes, so the
 * migration still starts moving within seconds rather than waiting for the whole
 * catalogue to be listed.
 *
 * The columns:
 *
 *   store_migrations.planned_at   when every entity's chunks were pinned. NULL
 *                                 means planning is still outstanding, and
 *                                 nothing may finalize the run while it is —
 *                                 otherwise a run whose first entity finished
 *                                 before the second was planned would close
 *                                 itself as "completed".
 *
 *   migration_entities.planned_items    how many records have been pinned so far,
 *                                       so a resumed planner knows where it got to.
 *
 *   migration_entities.last_source_id   the highest WooCommerce id pinned. Ids are
 *                                       listed ascending, so anything at or below
 *                                       this has already been claimed by an
 *                                       earlier chunk — which is what stops a
 *                                       record INSERTED mid-listing (a right
 *                                       shift) being pinned into two chunks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_migrations', function (Blueprint $table) {
            $table->timestamp('planned_at')->nullable()->after('started_at');
        });

        Schema::table('migration_entities', function (Blueprint $table) {
            $table->unsignedInteger('planned_items')->default(0)->after('total_items');
            $table->unsignedBigInteger('last_source_id')->nullable()->after('planned_items');
        });

        // Every run that already exists was planned the old way, in full, before
        // its batches were created. Leaving them NULL would tell the new guards
        // that planning never finished, so a finished run could never be closed
        // out and the watchdog would try to re-plan it for ever.
        DB::table('store_migrations')->whereNull('planned_at')->update([
            'planned_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('store_migrations', function (Blueprint $table) {
            $table->dropColumn('planned_at');
        });

        Schema::table('migration_entities', function (Blueprint $table) {
            $table->dropColumn(['planned_items', 'last_source_id']);
        });
    }
};
