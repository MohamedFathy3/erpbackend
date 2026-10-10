<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automotive_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('service_id')->constrained('automotive_services')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->string('type', 20); // initial | purchase | sale | return | adjustment
            $table->decimal('meters', 15, 3);          // موجب = دخول، سالب = خروج
            $table->decimal('balance_after', 15, 3);
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->string('reference_type', 50)->nullable(); // service_order | pos_invoice | purchase ...
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['reference_type', 'reference_id']);
            $table->index(['service_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automotive_stock_movements');
    }
};