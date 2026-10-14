<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('finances', 'cashier_shift_id')) {
            Schema::table('finances', function (Blueprint $table): void {
                $table->foreignId('cashier_shift_id')
                    ->nullable()
                    ->after('branch_id')
                    ->constrained('cashier_shifts')
                    ->nullOnDelete();
                $table->index(['cashier_shift_id', 'date'], 'finances_shift_date_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('finances', 'cashier_shift_id')) {
            Schema::table('finances', function (Blueprint $table): void {
                $table->dropIndex('finances_shift_date_idx');
                $table->dropConstrainedForeignId('cashier_shift_id');
            });
        }
    }
};
