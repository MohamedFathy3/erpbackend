<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            // ✅ product_id يبقى nullable
            if (Schema::hasColumn('invoice_items', 'product_id')) {
                $table->unsignedBigInteger('product_id')->nullable()->change();
            }
            
            // ✅ automotive_service_id
            if (!Schema::hasColumn('invoice_items', 'automotive_service_id')) {
                $table->unsignedBigInteger('automotive_service_id')->nullable()->after('product_id');
                $table->index('automotive_service_id');
            }
            
            // ✅ meter_quantity
            if (!Schema::hasColumn('invoice_items', 'meter_quantity')) {
                $table->decimal('meter_quantity', 10, 3)->nullable()->after('quantity');
            }
            
            // ✅ vehicle_size
            if (!Schema::hasColumn('invoice_items', 'vehicle_size')) {
                $table->string('vehicle_size', 20)->nullable()->after('meter_quantity');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropIndex(['automotive_service_id']);
            $table->dropColumn(['automotive_service_id', 'meter_quantity', 'vehicle_size']);
        });
    }
};