<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automotive_services', function (Blueprint $table) {
            $table->json('vehicle_sizes')->nullable()->after('stock_quantity');
        });

        // ترحيل البيانات القديمة (صغير / كبير) إلى المصفوفة الجديدة
        DB::table('automotive_services')
            ->where('item_type', 'product')
            ->orderBy('id')
            ->get()
            ->each(function ($row) {
                $sizes = [];

                if ((float) $row->small_vehicle_quantity > 0) {
                    $sizes[] = [
                        'name' => 'صغير',
                        'meters' => round((float) $row->small_vehicle_quantity, 3),
                        'price' => round((float) ($row->small_vehicle_price ?: $row->small_vehicle_quantity * $row->selling_price), 2),
                    ];
                }
                if ((float) $row->large_vehicle_quantity > 0) {
                    $sizes[] = [
                        'name' => 'كبير',
                        'meters' => round((float) $row->large_vehicle_quantity, 3),
                        'price' => round((float) ($row->large_vehicle_price ?: $row->large_vehicle_quantity * $row->selling_price), 2),
                    ];
                }

                if ($sizes) {
                    DB::table('automotive_services')
                        ->where('id', $row->id)
                        ->update(['vehicle_sizes' => json_encode($sizes, JSON_UNESCAPED_UNICODE)]);
                }
            });

        // الخدمات (غير المنتجات) بدون تكلفة
        DB::table('automotive_services')->where('item_type', 'service')->update(['estimated_cost' => 0]);
    }

    public function down(): void
    {
        Schema::table('automotive_services', function (Blueprint $table) {
            $table->dropColumn('vehicle_sizes');
        });
    }
};