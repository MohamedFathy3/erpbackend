<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('retry_of_event_id')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('tenant_name')->nullable();
            $table->string('tenant_slug')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_type', 80)->nullable();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('event_type', 32);
            $table->string('outcome', 16);
            $table->string('http_method', 12);
            $table->string('request_path', 512);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->json('query_data')->nullable();
            $table->json('request_data')->nullable();
            $table->text('error_summary')->nullable();
            $table->string('client_ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at'], 'audit_events_tenant_created_idx');
            $table->index(['actor_id', 'actor_type', 'created_at'], 'audit_events_actor_created_idx');
            $table->index(['outcome', 'created_at'], 'audit_events_outcome_created_idx');
            $table->index(['event_type', 'created_at'], 'audit_events_type_created_idx');
            $table->index('retry_of_event_id', 'audit_events_retry_parent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
