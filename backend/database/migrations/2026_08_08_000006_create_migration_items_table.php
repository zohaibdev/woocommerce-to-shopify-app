<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Mapping / idempotency table — the heart of duplicate prevention.
        Schema::create('migration_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('migration_id')->constrained('store_migrations')->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type');
            $table->unsignedBigInteger('woocommerce_id');
            $table->string('shopify_id')->nullable(); // gid://shopify/Product/123
            $table->enum('status', ['pending', 'success', 'failed', 'skipped'])->default('pending');
            $table->text('error')->nullable();
            $table->string('payload_hash')->nullable();
            $table->timestamps();

            // Guarantees one mapping per source object per tenant per entity type.
            $table->unique(['shop_id', 'entity_type', 'woocommerce_id'], 'migration_items_tenant_source_unique');
            $table->index(['migration_id', 'entity_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_items');
    }
};
