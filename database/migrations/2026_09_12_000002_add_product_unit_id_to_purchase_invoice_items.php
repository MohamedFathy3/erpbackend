<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'purchase_invoice_items';
        if (!Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'product_unit_id')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->unsignedBigInteger('product_unit_id')->nullable()->after('product_id');
            $table->index('product_unit_id');
        });
    }

    public function down(): void
    {
        $tableName = 'purchase_invoice_items';
        if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'product_unit_id')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropIndex(['product_unit_id']);
            $table->dropColumn('product_unit_id');
        });
    }
};
