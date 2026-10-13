<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('return_items')) {
            return;
        }

        Schema::table('return_items', function (Blueprint $table): void {
            if (Schema::hasColumn('return_items', 'product_id')) {
                $table->unsignedBigInteger('product_id')->nullable()->change();
            }
            if (!Schema::hasColumn('return_items', 'automotive_service_id')) {
                $table->unsignedBigInteger('automotive_service_id')->nullable()->after('product_id')->index();
            }
            if (!Schema::hasColumn('return_items', 'meter_quantity')) {
                $table->decimal('meter_quantity', 10, 3)->nullable()->after('quantity');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('return_items')) {
            return;
        }

        Schema::table('return_items', function (Blueprint $table): void {
            foreach (['automotive_service_id', 'meter_quantity'] as $column) {
                if (Schema::hasColumn('return_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
