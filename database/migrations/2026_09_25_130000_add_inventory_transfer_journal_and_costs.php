<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('inventory_transfer_requests') && !Schema::hasColumn('inventory_transfer_requests', 'journal_entry_id')) {
            Schema::table('inventory_transfer_requests', function (Blueprint $table): void {
                $table->foreignId('journal_entry_id')->nullable()->after('status')->constrained('journal_entries')->nullOnDelete();
            });
        }

        if (Schema::hasTable('inventory_movements')) {
            Schema::table('inventory_movements', function (Blueprint $table): void {
                if (!Schema::hasColumn('inventory_movements', 'unit_cost')) {
                    $table->decimal('unit_cost', 14, 4)->default(0);
                }
                if (!Schema::hasColumn('inventory_movements', 'total_cost')) {
                    $table->decimal('total_cost', 14, 2)->default(0);
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('inventory_movements')) {
            Schema::table('inventory_movements', function (Blueprint $table): void {
                foreach (['unit_cost', 'total_cost'] as $column) {
                    if (Schema::hasColumn('inventory_movements', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('inventory_transfer_requests') && Schema::hasColumn('inventory_transfer_requests', 'journal_entry_id')) {
            Schema::table('inventory_transfer_requests', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('journal_entry_id');
            });
        }
    }
};
