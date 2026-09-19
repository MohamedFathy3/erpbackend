<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('automotive_service_order_items') && !Schema::hasColumn('automotive_service_order_items', 'status')) {
            Schema::table('automotive_service_order_items', function (Blueprint $table): void {
                $table->string('status')->default('pending')->after('approved');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('automotive_service_order_items') && Schema::hasColumn('automotive_service_order_items', 'status')) {
            Schema::table('automotive_service_order_items', fn (Blueprint $table) => $table->dropColumn('status'));
        }
    }
};
