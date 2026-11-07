<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_prices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('color_id')->nullable()->constrained()->nullOnDelete();
            $table->string('price_type', 20);
            $table->decimal('min_quantity', 18, 6)->default(1);
            $table->decimal('price', 10, 2);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['product_id', 'price_type', 'unit_id', 'color_id', 'min_quantity'], 'product_prices_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
    }
};
