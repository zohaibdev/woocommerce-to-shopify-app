<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('migration_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('migration_id')->constrained('store_migrations')->cascadeOnDelete();
            $table->enum('level', ['info', 'warning', 'error'])->default('info');
            $table->string('entity_type')->nullable();
            $table->string('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['migration_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_logs');
    }
};
