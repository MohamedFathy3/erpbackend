<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  if(Schema::hasTable('treasuries')&&!Schema::hasColumn('treasuries','account_id')) Schema::table('treasuries',fn(Blueprint $t)=>$t->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete());
  if(Schema::hasTable('banks')&&!Schema::hasColumn('banks','account_id')) Schema::table('banks',fn(Blueprint $t)=>$t->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete());
  if(Schema::hasTable('transfers')&&!Schema::hasColumn('transfers','journal_entry_id')) Schema::table('transfers',fn(Blueprint $t)=>$t->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete());
 }
 public function down(): void { foreach(['treasuries','banks'] as $table) if(Schema::hasTable($table)&&Schema::hasColumn($table,'account_id')) Schema::table($table,fn(Blueprint $t)=>$t->dropConstrainedForeignId('account_id')); if(Schema::hasTable('transfers')&&Schema::hasColumn('transfers','journal_entry_id')) Schema::table('transfers',fn(Blueprint $t)=>$t->dropConstrainedForeignId('journal_entry_id')); }
};
