<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('employee_payrolls', function (Blueprint $table) {
            $table->foreignId('treasury_id')->nullable()->after('employee_id')->constrained('treasuries')->nullOnDelete();
            $table->foreignId('finance_id')->nullable()->after('treasury_id')->constrained('finances')->nullOnDelete();
        });
        Schema::table('employee_advances', function (Blueprint $table) {
            $table->foreignId('treasury_id')->nullable()->after('employee_id')->constrained('treasuries')->nullOnDelete();
            $table->foreignId('finance_id')->nullable()->after('treasury_id')->constrained('finances')->nullOnDelete();
        });
        Schema::table('employee_advance_payments', function (Blueprint $table) {
            $table->foreignId('treasury_id')->nullable()->after('advance_id')->constrained('treasuries')->nullOnDelete();
        });
    }
    public function down(): void {
        Schema::table('employee_advance_payments', fn(Blueprint $table) => $table->dropConstrainedForeignId('treasury_id'));
        Schema::table('employee_advances', function (Blueprint $table) { $table->dropConstrainedForeignId('finance_id'); $table->dropConstrainedForeignId('treasury_id'); });
        Schema::table('employee_payrolls', function (Blueprint $table) { $table->dropConstrainedForeignId('finance_id'); $table->dropConstrainedForeignId('treasury_id'); });
    }
};
