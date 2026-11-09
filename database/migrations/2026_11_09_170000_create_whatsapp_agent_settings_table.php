<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('whatsapp_agent_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_account_id')->constrained('whatsapp_accounts')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->string('agent_name', 120)->default('مساعد خدمة العملاء');
            $table->string('timezone', 64)->default('UTC');
            $table->text('business_context')->nullable();
            $table->text('instructions')->nullable();
            $table->string('handoff_message', 500)->default('سأحوّل محادثتك إلى أحد أعضاء فريقنا لمساعدتك.');
            $table->json('enabled_actions')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'whatsapp_account_id'], 'wa_agent_settings_tenant_account_uniq');
            $table->index(['tenant_id', 'is_enabled'], 'wa_agent_settings_tenant_enabled_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_agent_settings');
    }
};
