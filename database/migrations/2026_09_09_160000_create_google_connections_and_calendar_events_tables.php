<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('google_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('google_account_id')->nullable();
            $table->string('google_email')->nullable();
            $table->string('google_name')->nullable();
            $table->string('scopes', 1000)->nullable();
            $table->string('status', 20)->default('connected')->index();
            $table->timestamps();
            $table->unique(['user_id', 'tenant_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('google_connection_id')->nullable()->constrained('google_connections')->nullOnDelete();
            $table->string('google_event_id')->nullable()->index();
            $table->string('calendar_id')->default('primary');
            $table->string('summary');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 20)->default('confirmed');
            $table->json('google_payload')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('google_connections');
    }
};
