<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اختياري لكن مهم: لو products.stock عمود integer فالأمتار الكسرية (مثل 12.5 م)
 * ستُقرَّب. هذه الهجرة تحوّله إلى decimal(15,3). راجع أي كود يفترض أنه integer قبل التشغيل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('stock', 15, 3)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->integer('stock')->default(0)->change();
        });
    }
};