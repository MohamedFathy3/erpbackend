<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('event_key')->unique();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('event');
            $table->string('status')->default('completed');
            $table->foreignId('inventory_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['source_type', 'source_id']);
            $table->index(['event', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_transactions');
    }
};
