<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { foreach(['finances','revenues'] as $tableName) if(Schema::hasTable($tableName)&&!Schema::hasColumn($tableName,'journal_entry_id')) Schema::table($tableName,fn(Blueprint $t)=>$t->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete()); } public function down(): void { foreach(['finances','revenues'] as $tableName) if(Schema::hasTable($tableName)&&Schema::hasColumn($tableName,'journal_entry_id')) Schema::table($tableName,fn(Blueprint $t)=>$t->dropConstrainedForeignId('journal_entry_id')); } };
