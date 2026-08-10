<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            // Expiring offline tokens (Shopify, Dec 2025+). Permanent offline
            // tokens are deprecated and must be exchanged for expiring ones.
            $table->text('refresh_token')->nullable()->after('access_token');
            $table->timestamp('token_expires_at')->nullable()->after('refresh_token');
            $table->timestamp('refresh_token_expires_at')->nullable()->after('token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['refresh_token', 'token_expires_at', 'refresh_token_expires_at']);
        });
    }
};
