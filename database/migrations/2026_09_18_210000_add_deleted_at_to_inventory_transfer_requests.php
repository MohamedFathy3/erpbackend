<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('inventory_transfer_requests') && !Schema::hasColumn('inventory_transfer_requests', 'deleted_at')) {
            Schema::table('inventory_transfer_requests', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('inventory_transfer_requests') && Schema::hasColumn('inventory_transfer_requests', 'deleted_at')) {
            Schema::table('inventory_transfer_requests', function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }
};
