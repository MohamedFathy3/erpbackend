<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * تنظيف البيانات القديمة: الخدمات (item_type = service) كانت تُحفظ بمخزون 999999.
 * هذه الـ migration تصفّر المخزون لها ولمنتجاتها المرتبطة. لا تغيّر شكل الجداول.
 */
return new class extends Migration {
    public function up(): void
    {
        $productIds = DB::table('automotive_services')
            ->where('item_type', 'service')
            ->whereNotNull('product_id')
            ->pluck('product_id');

        if ($productIds->isNotEmpty()) {
            foreach ($productIds->chunk(500) as $chunk) {
                DB::table('products')->whereIn('id', $chunk)->update(['stock' => 0]);
                DB::table('product_warehouse')->whereIn('product_id', $chunk)->update(['stock' => 0]);
            }
        }

        DB::table('automotive_services')
            ->where('item_type', 'service')
            ->update(['stock_quantity' => 0, 'estimated_cost' => 0]);
    }

    public function down(): void
    {
        // لا رجوع: القيمة 999999 كانت خاطئة.
    }
};