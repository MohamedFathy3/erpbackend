<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 private function dropActorForeignKey(string $table, string $column): void {
  if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) return;
  if (DB::connection()->getDriverName() !== 'mysql') return;
  $constraint = DB::table('information_schema.KEY_COLUMN_USAGE')
   ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
   ->where('TABLE_NAME', $table)->where('COLUMN_NAME', $column)
   ->whereNotNull('REFERENCED_TABLE_NAME')->value('CONSTRAINT_NAME');
  if ($constraint) DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`");
 }
 public function up(): void {
  foreach (['financial_periods:closed_by','purchase_invoice_payments:created_by','journal_entries:created_by','accounting_documents:created_by','transfers:created_by','treasury_transactions:created_by','sales_invoice_payments:created_by','inventory_movements:created_by','operational_movements:created_by'] as $reference) { [$table,$column]=explode(':',$reference); $this->dropActorForeignKey($table,$column); }
  if (Schema::hasTable('financial_periods') && !Schema::hasColumn('financial_periods','closed_by_type')) Schema::table('financial_periods', fn(Blueprint $t)=>$t->string('closed_by_type')->nullable()->after('closed_by'));
  foreach (['purchase_invoice_payments','journal_entries','accounting_documents','transfers','treasury_transactions','sales_invoice_payments','inventory_movements','operational_movements'] as $table) if (Schema::hasTable($table) && Schema::hasColumn($table,'created_by') && !Schema::hasColumn($table,'created_by_type')) Schema::table($table, fn(Blueprint $t)=>$t->string('created_by_type')->nullable()->after('created_by'));
 }
 public function down(): void { }
};
