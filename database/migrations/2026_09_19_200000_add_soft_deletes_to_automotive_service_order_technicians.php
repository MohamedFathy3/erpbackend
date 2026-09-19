<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('automotive_service_order_technicians') && !Schema::hasColumn('automotive_service_order_technicians', 'deleted_at')) {
            Schema::table('automotive_service_order_technicians', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('automotive_service_order_technicians') && Schema::hasColumn('automotive_service_order_technicians', 'deleted_at')) {
            Schema::table('automotive_service_order_technicians', function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }
};
