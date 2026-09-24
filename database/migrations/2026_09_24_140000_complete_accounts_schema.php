<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  if (!Schema::hasTable('accounts')) return;
  Schema::table('accounts', function (Blueprint $t) {
   if (!Schema::hasColumn('accounts','normal_balance')) $t->string('normal_balance')->default('debit')->after('account_type');
   if (!Schema::hasColumn('accounts','is_header')) $t->boolean('is_header')->default(false)->after('normal_balance');
   if (!Schema::hasColumn('accounts','is_active')) $t->boolean('is_active')->default(true)->after('is_header');
   if (!Schema::hasColumn('accounts','description')) $t->text('description')->nullable()->after('is_active');
   if (!Schema::hasColumn('accounts','branch_id')) $t->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
   if (!Schema::hasColumn('accounts','company_id')) $t->unsignedBigInteger('company_id')->nullable()->index();
  });
 }
 public function down(): void {
  if (!Schema::hasTable('accounts')) return;
  Schema::table('accounts', function (Blueprint $t) {
   foreach (['branch_id','company_id','description','is_active','is_header','normal_balance'] as $column) if (Schema::hasColumn('accounts',$column)) { if ($column==='branch_id') $t->dropConstrainedForeignId('branch_id'); else $t->dropColumn($column); }
  });
 }
};
