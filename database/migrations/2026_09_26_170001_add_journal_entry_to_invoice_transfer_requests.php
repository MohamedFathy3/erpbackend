<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { if (Schema::hasTable('invoice_transfer_requests') && !Schema::hasColumn('invoice_transfer_requests', 'journal_entry_id')) Schema::table('invoice_transfer_requests', fn (Blueprint $table) => $table->foreignId('journal_entry_id')->nullable()->after('approved_at')->constrained('journal_entries')->nullOnDelete()); }
    public function down(): void { if (Schema::hasTable('invoice_transfer_requests') && Schema::hasColumn('invoice_transfer_requests', 'journal_entry_id')) Schema::table('invoice_transfer_requests', fn (Blueprint $table) => $table->dropConstrainedForeignId('journal_entry_id')); }
};
