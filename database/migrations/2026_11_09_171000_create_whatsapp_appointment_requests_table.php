<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('whatsapp_appointment_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_account_id')->constrained('whatsapp_accounts')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            $table->foreignId('source_message_id')->nullable()->unique()->constrained('whatsapp_conversation_messages')->nullOnDelete();
            $table->string('contact_phone', 32);
            $table->string('service_summary', 500)->nullable();
            $table->string('requested_time_text', 500)->nullable();
            $table->text('customer_details')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->dateTime('scheduled_starts_at')->nullable();
            $table->dateTime('scheduled_ends_at')->nullable();
            $table->foreignId('calendar_event_id')->nullable()->constrained('calendar_events')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'created_at'], 'wa_appointment_tenant_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_appointment_requests');
    }
};
