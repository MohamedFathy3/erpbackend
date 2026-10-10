<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up(): void
{
    Schema::table('automotive_stock_movements', function (Blueprint $table) {
        $table->softDeletes(); // بيضيف عمود deleted_at
    });
}

public function down(): void
{
    Schema::table('automotive_stock_movements', function (Blueprint $table) {
        $table->dropSoftDeletes();
    });
}
};
