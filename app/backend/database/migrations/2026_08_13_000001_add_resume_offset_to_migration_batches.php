<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batches become resumable. `processed_offset` records how many items of the page
 * were already handled, so a worker that is killed mid-batch (shared-hosting cron
 * with --max-time, or a queue retry_after re-reservation) picks up where it left
 * off instead of restarting the page and double-counting every item.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('migration_batches', function (Blueprint $table) {
            $table->unsignedInteger('processed_offset')->default(0)->after('per_page');
            $table->index(['migration_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('migration_batches', function (Blueprint $table) {
            $table->dropIndex(['migration_id', 'status']);
            $table->dropColumn('processed_offset');
        });
    }
};
