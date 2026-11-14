<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products') || !Schema::hasColumn('products', 'stock')) {
            return;
        }

        if (Schema::getColumnType('products', 'stock') === 'integer') {
            return;
        }

        $bounds = DB::table('products')
            ->selectRaw('MAX(ROUND(stock)) AS max_stock, MIN(ROUND(stock)) AS min_stock')
            ->first();

        if (($bounds->max_stock ?? 0) > 2147483647 || ($bounds->min_stock ?? 0) < -2147483648) {
            throw new RuntimeException('Cannot convert products.stock to integer because a stock value exceeds the integer range.');
        }

        DB::table('products')->whereNull('stock')->update(['stock' => 0]);
        DB::table('products')->update(['stock' => DB::raw('ROUND(stock)')]);

        Schema::table('products', function (Blueprint $table): void {
            $table->integer('stock')->default(0)->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('products') || !Schema::hasColumn('products', 'stock')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('stock', 18, 6)->default(0)->change();
        });
    }
};
