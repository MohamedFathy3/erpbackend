<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = ['sales_invoices', 'purchase_invoices', 'sales_invoice_returns', 'purchase_returns', 'return_invoices'];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('posting_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
                $table->string('workflow_status')->default('pending')->index();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['posting_journal_entry_id']);
                $table->dropColumn(['posting_journal_entry_id', 'workflow_status']);
            });
        }
    }
};
