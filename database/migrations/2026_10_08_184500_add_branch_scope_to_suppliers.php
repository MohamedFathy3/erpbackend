<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('suppliers')) return;

        if (!Schema::hasColumn('suppliers', 'branch_id')) {
            Schema::table('suppliers', function (Blueprint $table): void {
                $table->foreignId('branch_id')->nullable()->after('tenant_id')->constrained('branches')->nullOnDelete();
                $table->index(['tenant_id', 'branch_id']);
            });
        }

        // Preserve the existing behavior for old records by assigning each supplier
        // to the branch used by its latest purchase invoice. If it has no invoice,
        // use the tenant's first branch as a safe legacy fallback.
        if (Schema::hasColumn('purchase_invoices', 'branch_id')) {
            DB::table('suppliers')->whereNull('branch_id')->orderBy('id')->chunkById(200, function ($suppliers): void {
                foreach ($suppliers as $supplier) {
                    $branchId = DB::table('purchase_invoices')
                        ->where('supplier_id', $supplier->id)
                        ->whereNotNull('branch_id')
                        ->orderByDesc('created_at')
                        ->value('branch_id');
                    $branchId ??= DB::table('branches')
                        ->where('tenant_id', $supplier->tenant_id)
                        ->orderBy('id')
                        ->value('id');
                    if ($branchId) {
                        DB::table('suppliers')->where('id', $supplier->id)->update(['branch_id' => $branchId]);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('suppliers') || !Schema::hasColumn('suppliers', 'branch_id')) return;

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropForeign(['branch_id']);
            $table->dropIndex(['tenant_id', 'branch_id']);
            $table->dropColumn('branch_id');
        });
    }
};
