<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['purchase_returns', 'inventory_logs'] as $tableName) {
            if (!Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'branch_id')) continue;
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('branch_id')->nullable()->after('id')->index()->constrained('branches')->nullOnDelete();
            });
        }

        if (Schema::hasTable('purchase_returns') && Schema::hasColumn('purchase_returns', 'branch_id')) {
            DB::statement("UPDATE purchase_returns r JOIN purchase_invoices i ON i.id = r.purchase_invoices_id SET r.branch_id = i.branch_id WHERE r.branch_id IS NULL");
        }
        if (Schema::hasTable('inventory_logs') && Schema::hasColumn('inventory_logs', 'branch_id')) {
            DB::statement("UPDATE inventory_logs l JOIN warehouses w ON w.id = l.warehouse_id SET l.branch_id = w.branch_id WHERE l.branch_id IS NULL");
        }
    }

    public function down(): void
    {
        foreach (['purchase_returns', 'inventory_logs'] as $tableName) {
            if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'branch_id')) continue;
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropForeign(['branch_id']);
                $table->dropIndex($tableName . '_branch_id_index');
                $table->dropColumn('branch_id');
            });
        }
    }
};
