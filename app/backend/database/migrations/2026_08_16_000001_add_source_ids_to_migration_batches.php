<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pins the exact WooCommerce ids each batch is responsible for.
 *
 * Batches used to be addressed purely as `page` + `per_page`, which WordPress
 * turns into a deep SQL OFFSET. Two things follow from that, and both of them
 * only show up on a large store:
 *
 *  1. SPEED. Page 500 of a 50k catalogue is `LIMIT 100 OFFSET 49900` across
 *     wp_posts + wp_postmeta, with the full product object assembled for every
 *     row. On shared hosting that is 30-90s — past the client's 60s timeout, so
 *     the deepest pages of the biggest catalogues were the ones that failed.
 *     Worse, the 35s time budget means one page takes several jobs to finish,
 *     and each of those re-ran that same query.
 *
 *  2. CORRECTNESS. Ordering is pinned to id ASC so a product ADDED mid-run lands
 *     at the end where it disturbs nothing. A product DELETED mid-run has no
 *     such courtesy: every later page shifts one place left, and because
 *     `processed_offset` is an absolute position within a page, a record can
 *     fall through the gap between two resumes and simply never be migrated.
 *
 * Resolving the page to a concrete id list ONCE, and fetching by `include=`
 * afterwards, fixes both: `include` is a post__in lookup that costs the same at
 * any depth, and the ids cannot shift underneath a resume because they were
 * decided before it started.
 *
 * Nullable on purpose — a batch planned by an older release, or an entity whose
 * source cannot be listed by id, falls back to the original page/offset path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migration_batches', function (Blueprint $table) {
            $table->json('source_ids')->nullable()->after('per_page');
        });
    }

    public function down(): void
    {
        Schema::table('migration_batches', function (Blueprint $table) {
            $table->dropColumn('source_ids');
        });
    }
};
