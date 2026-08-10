<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('woocommerce_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('store_url');
            $table->text('consumer_key');    // encrypted cast
            $table->text('consumer_secret'); // encrypted cast
            $table->enum('status', ['connected', 'error', 'disconnected'])->default('disconnected');
            $table->string('wc_version')->nullable();
            $table->string('wp_version')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('woocommerce_connections');
    }
};
