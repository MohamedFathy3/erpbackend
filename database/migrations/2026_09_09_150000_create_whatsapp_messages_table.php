<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('whatsapp_last_inbound_at')->nullable()->index();
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('to_phone', 20);
            $table->string('type', 20);
            $table->string('template_name')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->string('provider_message_id')->nullable()->unique();
            $table->json('payload')->nullable();
            $table->json('meta_response')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('whatsapp_last_inbound_at');
        });
    }
};
