<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['treasury_transactions', 'invoice_transfer_requests'] as $tableName) {
            if (!Schema::hasTable($tableName)) continue;
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (!Schema::hasColumn($tableName, 'tenant_id')) {
                    $table->foreignId('tenant_id')->nullable()->after('id')->index()->constrained('tenants')->nullOnDelete();
                }
                if (!Schema::hasColumn($tableName, 'branch_id')) {
                    $table->foreignId('branch_id')->nullable()->after('tenant_id')->index()->constrained('branches')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('treasury_transactions')) {
            DB::statement('UPDATE treasury_transactions t JOIN treasuries x ON x.id = t.treasury_id SET t.tenant_id = x.tenant_id, t.branch_id = x.branch_id WHERE t.tenant_id IS NULL OR t.branch_id IS NULL');
        }
        if (Schema::hasTable('invoice_transfer_requests')) {
            DB::statement("UPDATE invoice_transfer_requests r JOIN invoices i ON r.invoice_type = 'invoice' AND i.id = r.invoice_id SET r.tenant_id = i.tenant_id, r.branch_id = i.branch_id WHERE r.tenant_id IS NULL OR r.branch_id IS NULL");
            DB::statement("UPDATE invoice_transfer_requests r JOIN sales_invoices i ON r.invoice_type IN ('sales_invoice','sales-invoice') AND i.id = r.invoice_id SET r.tenant_id = i.tenant_id, r.branch_id = i.branch_id WHERE r.tenant_id IS NULL OR r.branch_id IS NULL");
            DB::statement('UPDATE invoice_transfer_requests r JOIN cashier_shifts s ON s.id = r.cashier_shift_id JOIN employees e ON e.id = s.employee_id SET r.tenant_id = COALESCE(r.tenant_id, e.tenant_id), r.branch_id = COALESCE(r.branch_id, e.branch_id) WHERE r.tenant_id IS NULL OR r.branch_id IS NULL');
        }
    }

    public function down(): void
    {
        foreach (['treasury_transactions', 'invoice_transfer_requests'] as $tableName) {
            if (!Schema::hasTable($tableName)) continue;
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (Schema::hasColumn($tableName, 'branch_id')) $table->dropForeign(['branch_id']);
                if (Schema::hasColumn($tableName, 'tenant_id')) $table->dropForeign(['tenant_id']);
                foreach (['branch_id', 'tenant_id'] as $column) if (Schema::hasColumn($tableName, $column)) $table->dropColumn($column);
            });
        }
    }
};
