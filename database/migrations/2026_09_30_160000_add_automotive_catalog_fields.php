<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automotive_services', function (Blueprint $table): void {
            $table->string('item_type')->default('service')->after('code');
            $table->string('unit')->nullable()->after('item_type');
            $table->decimal('small_vehicle_quantity', 12, 3)->nullable()->after('selling_price');
            $table->decimal('large_vehicle_quantity', 12, 3)->nullable()->after('small_vehicle_quantity');
            $table->decimal('small_vehicle_price', 14, 2)->nullable()->after('large_vehicle_quantity');
            $table->decimal('large_vehicle_price', 14, 2)->nullable()->after('small_vehicle_price');
            $table->decimal('stock_quantity', 14, 3)->default(0)->after('large_vehicle_price');
            $table->foreignId('product_id')->nullable()->after('stock_quantity')->constrained('products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('automotive_services', function (Blueprint $table): void {
            $table->dropForeign(['product_id']);
            $table->dropColumn([
                'item_type', 'unit', 'small_vehicle_quantity', 'large_vehicle_quantity',
                'small_vehicle_price', 'large_vehicle_price', 'stock_quantity', 'product_id',
            ]);
        });
    }
};
