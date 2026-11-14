<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'stock')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->integer('stock')->default(0)->change();
            });
        }

        if (Schema::hasColumn('product_warehouse', 'stock')) {
            Schema::table('product_warehouse', function (Blueprint $table): void {
                $table->integer('stock')->default(0)->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'stock')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->decimal('stock', 18, 6)->default(0)->change();
            });
        }

        if (Schema::hasColumn('product_warehouse', 'stock')) {
            Schema::table('product_warehouse', function (Blueprint $table): void {
                $table->decimal('stock', 18, 6)->default(0)->change();
            });
        }
    }
};
***
