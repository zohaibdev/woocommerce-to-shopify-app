<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // --- Super admin users -------------------------------------------------
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_super')->default(true);
            $table->string('api_token_hash')->nullable()->index();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        // --- Plans & subscriptions --------------------------------------------
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();          // free, basic, pro...
            $table->string('name');
            $table->decimal('price', 8, 2)->default(0);
            $table->string('interval')->default('monthly');
            $table->json('limits')->nullable();        // {products: 1000, ...}
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['active', 'pending', 'cancelled', 'expired', 'frozen'])->default('active');
            $table->string('shopify_charge_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
        });

        // --- Support tickets ---------------------------------------------------
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('migration_id')->nullable()->constrained('store_migrations')->nullOnDelete();
            $table->string('subject');
            $table->text('message');
            $table->string('contact_email')->nullable();
            $table->enum('status', ['open', 'pending', 'resolved', 'closed'])->default('open');
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->timestamp('last_reply_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority']);
        });

        Schema::create('ticket_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->enum('author_type', ['admin', 'merchant'])->default('admin');
            $table->string('author_name')->nullable();
            $table->text('message');
            $table->timestamps();
        });

        // --- Dashboard content shown in the merchant app -----------------------
        Schema::create('dashboard_contents', function (Blueprint $table) {
            $table->id();
            $table->enum('type', [
                'tutorial_video', 'useful_link', 'documentation_link',
                'support_link', 'email_info', 'announcement', 'other_resource',
            ])->index();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('url')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['type', 'enabled', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_contents');
        Schema::dropIfExists('ticket_replies');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('admin_users');
    }
};
