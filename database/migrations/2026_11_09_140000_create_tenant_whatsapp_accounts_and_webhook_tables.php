<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('whatsapp_accounts')) {
            Schema::create('whatsapp_accounts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('phone_number_id', 64);
                $table->string('business_account_id', 64);
                $table->string('display_phone_number', 32)->nullable();
                $table->string('verified_name')->nullable();
                $table->text('access_token');
                $table->text('app_secret')->nullable();
                $table->text('webhook_verify_token');
                $table->uuid('webhook_key')->unique('wa_accounts_webhook_key_uniq');
                $table->boolean('is_active')->default(true)->index('wa_accounts_active_idx');
                $table->timestamp('last_tested_at')->nullable();
                $table->string('connection_status', 32)->default('not_tested');
                $table->text('last_error')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'phone_number_id'], 'wa_accounts_tenant_phone_uniq');
                $table->index(['tenant_id', 'is_active'], 'wa_accounts_tenant_active_idx');
            });
        }

        if (!Schema::hasTable('whatsapp_webhook_events')) {
            Schema::create('whatsapp_webhook_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('whatsapp_account_id')->constrained('whatsapp_accounts')->cascadeOnDelete();
                $table->string('dedupe_key', 191);
                $table->string('event_type', 32);
                $table->string('status', 24)->default('received');
                $table->json('payload');
                $table->timestamp('processed_at')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'dedupe_key'], 'wa_events_tenant_dedupe_uniq');
                $table->index(['whatsapp_account_id', 'created_at'], 'wa_events_account_created_idx');
            });
        }

        if (!Schema::hasTable('whatsapp_conversations')) {
            Schema::create('whatsapp_conversations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('whatsapp_account_id')->constrained('whatsapp_accounts')->cascadeOnDelete();
                $table->string('contact_phone', 32);
                $table->string('contact_name')->nullable();
                $table->string('status', 24)->default('bot_active')->index('wa_conversations_status_idx');
                $table->timestamp('last_inbound_at')->nullable();
                $table->timestamps();
                $table->unique(['whatsapp_account_id', 'contact_phone'], 'wa_conversations_account_phone_uniq');
                $table->index(['tenant_id', 'updated_at'], 'wa_conversations_tenant_updated_idx');
            });
        }

        if (!Schema::hasTable('whatsapp_conversation_messages')) {
            Schema::create('whatsapp_conversation_messages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('whatsapp_account_id')->constrained('whatsapp_accounts')->cascadeOnDelete();
                $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
                $table->string('provider_message_id', 191)->nullable()->unique('wa_messages_provider_id_uniq');
                $table->string('direction', 12);
                $table->string('message_type', 32);
                $table->text('body')->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('provider_timestamp')->nullable();
                $table->timestamps();
            });
        }

        // The first migration attempt may have created this table before MySQL rejected
        // Laravel's generated (overlong) index name. Add a short index only when missing.
        if (!Schema::hasIndex('whatsapp_conversation_messages', ['tenant_id', 'conversation_id', 'created_at'])) {
            Schema::table('whatsapp_conversation_messages', function (Blueprint $table): void {
                $table->index(['tenant_id', 'conversation_id', 'created_at'], 'wa_msg_tenant_conv_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversation_messages');
        Schema::dropIfExists('whatsapp_conversations');
        Schema::dropIfExists('whatsapp_webhook_events');
        Schema::dropIfExists('whatsapp_accounts');
    }
};
