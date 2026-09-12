<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'inventory_variant_stocks';
        if (!Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'deleted_at')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        $tableName = 'inventory_variant_stocks';
        if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'deleted_at')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
