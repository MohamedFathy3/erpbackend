<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->nullableMorphs('related_to');
            $table->dateTime('due_date')->nullable()->index();
            $table->string('status', 20)->default('open')->index();
            $table->string('priority', 20)->default('normal');
            $table->foreignId('calendar_event_id')->nullable()->constrained('calendar_events')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'assigned_to', 'status']);
        });

        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->dateTime('remind_at')->index();
            $table->string('channel', 20)->default('in-app');
            $table->string('status', 20)->default('pending')->index();
            $table->dateTime('notified_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'remind_at']);
        });

        Schema::create('task_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reminder_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'is_read']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_notifications');
        Schema::dropIfExists('reminders');
        Schema::dropIfExists('tasks');
    }
};
