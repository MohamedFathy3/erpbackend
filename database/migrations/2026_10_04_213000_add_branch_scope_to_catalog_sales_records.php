<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'categories', 'colors', 'units', 'offers', 'products',
        'cashier_shifts', 'return_invoices', 'sales_invoice_returns',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (!Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'branch_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('branch_id')->nullable()->after('id')->index()->constrained('branches')->nullOnDelete();
            });
        }

        // Legacy catalog rows had no branch. Keep them visible by assigning the
        // first branch in their tenant; new rows are assigned by BaseModel.
        foreach (['categories', 'colors', 'units', 'offers', 'products'] as $tableName) {
            DB::statement("UPDATE {$tableName} x JOIN (SELECT tenant_id, MIN(id) AS branch_id FROM branches GROUP BY tenant_id) b ON b.tenant_id = x.tenant_id SET x.branch_id = b.branch_id WHERE x.branch_id IS NULL");
        }

        DB::statement("UPDATE cashier_shifts s JOIN employees e ON e.id = s.employee_id SET s.branch_id = e.branch_id WHERE s.branch_id IS NULL");
        DB::statement("UPDATE return_invoices r JOIN invoices i ON i.id = r.invoice_id SET r.branch_id = i.branch_id WHERE r.branch_id IS NULL");
        DB::statement("UPDATE sales_invoice_returns r JOIN sales_invoices i ON i.id = r.sales_invoice_id SET r.branch_id = i.branch_id WHERE r.branch_id IS NULL");
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $tableName) {
            if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'branch_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropForeign(['branch_id']);
                $table->dropIndex($tableName . '_branch_id_index');
                $table->dropColumn('branch_id');
            });
        }
    }
};
