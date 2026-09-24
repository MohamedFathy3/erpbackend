<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  if (Schema::hasTable('customers') && !Schema::hasColumn('customers','account_id')) Schema::table('customers', fn(Blueprint $t)=>$t->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete());
  if (Schema::hasTable('suppliers') && !Schema::hasColumn('suppliers','account_id')) Schema::table('suppliers', fn(Blueprint $t)=>$t->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete());
  if (Schema::hasTable('sales_invoices') && !Schema::hasColumn('sales_invoices','cogs_journal_entry_id')) Schema::table('sales_invoices', fn(Blueprint $t)=>$t->foreignId('cogs_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete());
 }
 public function down(): void {
  foreach (['customers','suppliers'] as $table) if (Schema::hasTable($table)&&Schema::hasColumn($table,'account_id')) Schema::table($table, fn(Blueprint $t)=>$t->dropConstrainedForeignId('account_id'));
  if (Schema::hasTable('sales_invoices')&&Schema::hasColumn('sales_invoices','cogs_journal_entry_id')) Schema::table('sales_invoices', fn(Blueprint $t)=>$t->dropConstrainedForeignId('cogs_journal_entry_id'));
 }
};
