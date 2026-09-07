<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'manufacturing_boms',
        'manufacturing_bom_items',
        'manufacturing_work_centers',
        'manufacturing_orders',
        'manufacturing_quality_inspections',
        'manufacturing_order_operations',
        'projects',
        'project_wbs_items',
        'project_claims',
        'project_claim_items',
        'inventory_movements',
        'manufacturing_cost_entries',
        'project_cost_entries',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (Schema::hasTable($tableName) && !Schema::hasColumn($tableName, 'deleted_at')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->softDeletes();
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'deleted_at')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropSoftDeletes();
                });
            }
        }
    }
};
