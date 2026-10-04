<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private array $tables = [
        'employee_payrolls' => 'employee_id',
        'employee_advances' => 'employee_id',
        'employee_advance_payments' => 'advance_id',
        'employee_financial_transactions' => 'employee_id',
        'employee_bonuses' => 'employee_id',
        'cashier_shifts' => 'employee_id',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName => $ownerColumn) {
            if (!Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'branch_id')) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('branch_id')->nullable()->after('id')->constrained('branches')->nullOnDelete();
                $table->index(['tenant_id', 'branch_id']);
            });
        }

        if (Schema::hasTable('employee_advance_payments')) {
            DB::statement('UPDATE employee_advance_payments p JOIN employee_advances a ON a.id = p.advance_id SET p.branch_id = a.branch_id WHERE p.branch_id IS NULL');
            DB::statement('UPDATE employee_advance_payments p JOIN employees e ON e.id = (SELECT a.employee_id FROM employee_advances a WHERE a.id = p.advance_id) SET p.branch_id = e.branch_id WHERE p.branch_id IS NULL');
        }
        foreach (['employee_payrolls', 'employee_advances', 'employee_financial_transactions', 'employee_bonuses', 'cashier_shifts'] as $tableName) {
            if (Schema::hasTable($tableName)) {
                DB::statement("UPDATE {$tableName} t JOIN employees e ON e.id = t.employee_id SET t.branch_id = e.branch_id WHERE t.branch_id IS NULL");
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->tables) as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'branch_id')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropForeign(['branch_id']);
                    $table->dropIndex(['tenant_id', 'branch_id']);
                    $table->dropColumn('branch_id');
                });
            }
        }
    }
};
