<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('migration_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('migration_id')->constrained('store_migrations')->cascadeOnDelete();
            $table->string('entity_type');
            $table->unsignedInteger('batch_number');
            $table->unsignedInteger('page');
            $table->unsignedInteger('per_page');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['migration_id', 'entity_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_batches');
    }
};
