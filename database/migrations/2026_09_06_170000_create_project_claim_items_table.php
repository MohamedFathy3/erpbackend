<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_claim_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_claim_id')->constrained('project_claims')->cascadeOnDelete();
            $table->foreignId('project_wbs_item_id')->constrained('project_wbs_items')->restrictOnDelete();
            $table->decimal('quantity', 14, 4)->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->timestamps();
            $table->unique(['project_claim_id', 'project_wbs_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_claim_items');
    }
};
