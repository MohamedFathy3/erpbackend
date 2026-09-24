<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices') && !Schema::hasColumn('invoices', 'treasury_id')) {
            Schema::table('invoices', function (Blueprint $blueprint): void {
                $blueprint->foreignId('treasury_id')->nullable()->constrained('treasuries')->nullOnDelete();
            });
        }

        foreach ([
            'invoices' => ['journal_entry_id', 'cogs_journal_entry_id', 'commission_journal_entry_id'],
            'invoice_payments' => ['journal_entry_id'],
        ] as $table => $columns) {
            if (!Schema::hasTable($table)) continue;
            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns): void {
                foreach ($columns as $column) {
                    if (!Schema::hasColumn($table, $column)) {
                        $blueprint->foreignId($column)->nullable()->constrained('journal_entries')->nullOnDelete();
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'invoices' => ['journal_entry_id', 'cogs_journal_entry_id', 'commission_journal_entry_id'],
            'invoice_payments' => ['journal_entry_id'],
        ] as $table => $columns) {
            if (!Schema::hasTable($table)) continue;
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropConstrainedForeignId($column));
                }
            }
        }
    }
};
