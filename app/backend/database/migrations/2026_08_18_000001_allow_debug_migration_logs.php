<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `migration_logs.level` was an ENUM('info','warning','error').
 *
 * The developer log adds a fourth level, `debug`, and on MySQL an ENUM does not
 * merely ignore a value it does not know — in strict mode it THROWS on the
 * insert. That failure would land inside MigrationRunner::log(), which is called
 * from the item loop, which is exactly the shape of the bug that took down whole
 * batches when `message` was a VARCHAR(255).
 *
 * Widened to a plain string rather than a longer ENUM: a level is a label, and
 * the next one should not need a schema change (or a deploy ordering) to be
 * usable. SQLite ignores the type either way, which is why the local suite would
 * never have caught this.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('migration_logs', function ($table) {
            $table->string('level', 20)->default('info')->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // Anything the old enum cannot express has to go, or the ALTER fails.
        DB::table('migration_logs')->whereNotIn('level', ['info', 'warning', 'error'])->delete();

        Schema::table('migration_logs', function ($table) {
            $table->enum('level', ['info', 'warning', 'error'])->default('info')->change();
        });
    }
};
