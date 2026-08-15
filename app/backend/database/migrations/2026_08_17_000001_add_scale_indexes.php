<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the queries that only hurt once a store is big.
 *
 * Every one of these was fine at a few hundred records and is a table scan at
 * twenty thousand — and the two that matter most run on a TIMER, not on demand:
 * the merchant's details page polls the activity log every three seconds for the
 * whole length of a run, and the watchdog sweeps every active migration on the
 * platform every five minutes.
 *
 * Indexes are added, never swapped: an existing one that has become redundant
 * costs a little write time, whereas dropping it on a live production table
 * risks the query it was covering. Sort that out separately if it ever matters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migration_logs', function (Blueprint $table) {
            // GET /api/migrations/{id}/logs is `where migration_id = ? order by
            // id desc limit 100`, polled every three seconds by every open
            // details page. The existing (migration_id, level) index answers the
            // WHERE and then leaves MySQL to sort tens of thousands of rows to
            // find the newest hundred — on every single poll. Ordering the index
            // by id turns that into a hundred-row backwards range scan.
            $table->index(['migration_id', 'id'], 'migration_logs_migration_id_id_index');
        });

        Schema::table('migration_items', function (Blueprint $table) {
            // Plan metering: PlanPool and PlanAllowance both count a shop's
            // successful records for the current billing period. That is
            // `shop_id + entity_type + status + created_at`, and it runs on the
            // preview endpoint the wizard calls, on every migration start, and
            // once per entity. The unique key starts with the right two columns
            // but stops there, so the status and date filters were applied by
            // reading every mapping row the shop has ever had.
            $table->index(
                ['shop_id', 'entity_type', 'status', 'created_at'],
                'migration_items_plan_metering_index',
            );
        });

        // migration_batches already has (migration_id, status) — added alongside
        // processed_offset in 2026_08_13_000001 — which is what nextBatch(),
        // failedBatchCount() and the watchdog's stall sweep need. Nothing to do.

        Schema::table('store_migrations', function (Blueprint $table) {
            // The watchdog asks for every pending/processing run ACROSS ALL
            // SHOPS every five minutes. There is no shop_id to narrow it, so
            // without this it reads the whole table — which grows forever, one
            // row per migration ever run on the platform.
            $table->index(['status', 'id'], 'store_migrations_status_id_index');
        });

        Schema::table('jobs', function (Blueprint $table) {
            // The database queue driver pops with
            // `where queue = ? and (reserved_at is null and available_at <= ? or
            // reserved_at <= ?) order by id`. Laravel ships an index on `queue`
            // alone, which is one distinct value here — so every worker polling
            // for work scanned every queued job. That is fine with one worker and
            // is exactly the wrong shape once eight of them poll in parallel,
            // which is the whole point of the worker pool.
            $table->index(['queue', 'reserved_at', 'available_at'], 'jobs_queue_reserved_available_index');
        });
    }

    public function down(): void
    {
        Schema::table('migration_logs', function (Blueprint $table) {
            $table->dropIndex('migration_logs_migration_id_id_index');
        });

        Schema::table('migration_items', function (Blueprint $table) {
            $table->dropIndex('migration_items_plan_metering_index');
        });

        Schema::table('store_migrations', function (Blueprint $table) {
            $table->dropIndex('store_migrations_status_id_index');
        });

        Schema::table('jobs', function (Blueprint $table) {
            $table->dropIndex('jobs_queue_reserved_available_index');
        });
    }
};
