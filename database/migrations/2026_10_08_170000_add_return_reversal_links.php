<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('return_invoices')) return;

        Schema::table('return_invoices', function (Blueprint $table): void {
            if (!Schema::hasColumn('return_invoices', 'cogs_journal_entry_id')) {
                $table->foreignId('cogs_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            }
            if (!Schema::hasColumn('return_invoices', 'commission_journal_entry_id')) {
                $table->foreignId('commission_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('return_invoices')) return;

        Schema::table('return_invoices', function (Blueprint $table): void {
            foreach (['cogs_journal_entry_id', 'commission_journal_entry_id'] as $column) {
                if (Schema::hasColumn('return_invoices', $column)) {
                    $table->dropForeign([$column]);
                    $table->dropColumn($column);
                }
            }
        });
    }
};
