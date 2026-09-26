<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { if (Schema::hasTable('inventory_logs') && !Schema::hasColumn('inventory_logs', 'journal_entry_id')) Schema::table('inventory_logs', fn (Blueprint $table) => $table->foreignId('journal_entry_id')->nullable()->after('difference')->constrained('journal_entries')->nullOnDelete()); }
    public function down(): void { if (Schema::hasTable('inventory_logs') && Schema::hasColumn('inventory_logs', 'journal_entry_id')) Schema::table('inventory_logs', fn (Blueprint $table) => $table->dropConstrainedForeignId('journal_entry_id')); }
};
