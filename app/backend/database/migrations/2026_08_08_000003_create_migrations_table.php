<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Named "store_migrations" to avoid clashing with Laravel's own "migrations" table.
        Schema::create('store_migrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('woocommerce_connection_id')->constrained('woocommerce_connections')->cascadeOnDelete();
            $table->enum('status', [
                'pending', 'processing', 'completed',
                'completed_with_errors', 'failed', 'cancelled', 'paused',
            ])->default('pending');
            $table->json('selected_entities');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_migrations');
    }
};
