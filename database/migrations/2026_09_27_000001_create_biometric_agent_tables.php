<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('biometric_agents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('created_by_type', 120)->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->char('token_hash', 64)->nullable()->unique();
            $table->string('status', 20)->default('offline');
            $table->timestamp('last_seen_at')->nullable();
            $table->string('agent_version', 32)->nullable();
            $table->unsignedInteger('devices_count')->default(0);
            $table->unsignedInteger('pending_events')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('biometric_agent_pairing_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->char('code_hash', 64)->unique();
            $table->string('created_by_type', 120);
            $table->unsignedBigInteger('created_by_id');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'expires_at']);
        });

        Schema::table('biometric_logs', function (Blueprint $table): void {
            if (!Schema::hasColumn('biometric_logs', 'agent_event_key')) {
                $table->char('agent_event_key', 64)->nullable()->unique();
            }
        });
    }

    public function down(): void
    {
        Schema::table('biometric_logs', function (Blueprint $table): void {
            if (Schema::hasColumn('biometric_logs', 'agent_event_key')) {
                $table->dropUnique(['agent_event_key']);
                $table->dropColumn('agent_event_key');
            }
        });
        Schema::dropIfExists('biometric_agent_pairing_codes');
        Schema::dropIfExists('biometric_agents');
    }
};
