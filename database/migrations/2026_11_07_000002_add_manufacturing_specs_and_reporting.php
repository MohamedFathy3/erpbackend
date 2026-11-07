<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('bom_items') && !Schema::hasColumn('bom_items', 'specifications')) {
            Schema::table('bom_items', fn (Blueprint $table) => $table->json('specifications')->nullable());
        }
        if (Schema::hasTable('manufacturing_stock_movements')) {
            Schema::table('manufacturing_stock_movements', function (Blueprint $table): void {
                if (!Schema::hasColumn('manufacturing_stock_movements', 'manufacturing_operation_id')) $table->unsignedBigInteger('manufacturing_operation_id')->nullable()->index();
                if (!Schema::hasColumn('manufacturing_stock_movements', 'reason')) $table->string('reason', 500)->nullable();
            });
        }
    }
    public function down(): void
    {
        if (Schema::hasTable('bom_items') && Schema::hasColumn('bom_items', 'specifications')) Schema::table('bom_items', fn (Blueprint $table) => $table->dropColumn('specifications'));
        if (Schema::hasTable('manufacturing_stock_movements')) Schema::table('manufacturing_stock_movements', function (Blueprint $table): void {
            if (Schema::hasColumn('manufacturing_stock_movements', 'manufacturing_operation_id')) { $table->dropIndex(['manufacturing_operation_id']); $table->dropColumn('manufacturing_operation_id'); }
            if (Schema::hasColumn('manufacturing_stock_movements', 'reason')) $table->dropColumn('reason');
        });
    }
};
