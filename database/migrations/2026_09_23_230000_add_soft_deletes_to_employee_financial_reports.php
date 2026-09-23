<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        foreach (['employee_payrolls', 'employee_advances', 'employee_advance_payments', 'employee_financial_transactions'] as $tableName) {
            if (Schema::hasTable($tableName) && !Schema::hasColumn($tableName, 'deleted_at')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->softDeletes());
            }
        }
    }
    public function down(): void {
        foreach (['employee_payrolls', 'employee_advances', 'employee_advance_payments', 'employee_financial_transactions'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'deleted_at')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropSoftDeletes());
            }
        }
    }
};
