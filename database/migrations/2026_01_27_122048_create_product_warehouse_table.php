
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_warehouse', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            $table->foreignId('warehouse_id')
                ->constrained('warehouses')
                ->cascadeOnDelete();

            $table->unsignedInteger('stock')->default(0);

            $table->decimal('cost', 10, 2)->nullable();

            $table->timestamps();

            // منع تكرار المنتج داخل نفس المخزن
            $table->unique(
                ['product_id', 'warehouse_id'],
                'product_warehouse_product_warehouse_unique'
            );

            $table->index('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_warehouse');
    }
};
