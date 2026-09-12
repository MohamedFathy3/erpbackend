<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_query_audits', function (Blueprint $table) {
            $table->id();
            // Sanctum can authenticate users, admins, or employees; keep a polymorphic actor ID.
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->text('question');
            $table->text('sql')->nullable();
            $table->json('parameters')->nullable();
            $table->boolean('validation_passed')->default(false);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->string('model')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('ai_query_audits'); }
};
