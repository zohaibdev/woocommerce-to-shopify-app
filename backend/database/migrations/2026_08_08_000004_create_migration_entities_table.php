<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('migration_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('migration_id')->constrained('store_migrations')->cascadeOnDelete();
            $table->string('entity_type');
            $table->enum('status', [
                'pending', 'processing', 'completed',
                'completed_with_errors', 'failed', 'cancelled', 'paused',
            ])->default('pending');
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('processed_items')->default(0);
            $table->unsignedInteger('successful_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->timestamps();

            $table->unique(['migration_id', 'entity_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_entities');
    }
};
