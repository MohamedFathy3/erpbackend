<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('product_warehouse') || !Schema::hasColumn('product_warehouse', 'stock')) {
            return;
        }

        if (Schema::getColumnType('product_warehouse', 'stock') === 'integer') {
            return;
        }

        $bounds = DB::table('product_warehouse')
            ->selectRaw('MAX(ROUND(stock)) AS max_stock, MIN(ROUND(stock)) AS min_stock')
            ->first();

        if (($bounds->max_stock ?? 0) > 2147483647 || ($bounds->min_stock ?? 0) < -2147483648) {
            throw new RuntimeException('Cannot convert product_warehouse.stock to integer because a stock value exceeds the integer range.');
        }

        DB::table('product_warehouse')->whereNull('stock')->update(['stock' => 0]);
        DB::table('product_warehouse')->update(['stock' => DB::raw('ROUND(stock)')]);

        Schema::table('product_warehouse', function (Blueprint $table): void {
            $table->integer('stock')->default(0)->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('product_warehouse') || !Schema::hasColumn('product_warehouse', 'stock')) {
            return;
        }

        Schema::table('product_warehouse', function (Blueprint $table): void {
            $table->decimal('stock', 18, 6)->default(0)->change();
        });
    }
};
