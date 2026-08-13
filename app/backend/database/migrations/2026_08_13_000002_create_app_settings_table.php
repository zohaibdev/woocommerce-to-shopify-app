<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-editable application settings (currently SMTP). Values are stored as one
 * encrypted JSON blob per key so credentials never sit in the database — or in a
 * .env the super admin has no way to edit.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
