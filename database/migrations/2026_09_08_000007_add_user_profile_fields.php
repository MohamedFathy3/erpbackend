<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('users', function (Blueprint $table) { foreach (['address_line_one','address_line_two','city','state','postal_code','website','profile','unhashed_password'] as $column) if (!Schema::hasColumn('users',$column)) $table->string($column)->nullable(); if (!Schema::hasColumn('users','members_count')) $table->unsignedInteger('members_count')->nullable(); if (!Schema::hasColumn('users','country_id')) $table->unsignedBigInteger('country_id')->nullable(); if (!Schema::hasColumn('users','business_est')) $table->string('business_est')->nullable(); if (!Schema::hasColumn('users','fpp')) $table->string('fpp')->nullable(); }); }
    public function down(): void { Schema::table('users', function (Blueprint $table) { foreach (['address_line_one','address_line_two','city','state','postal_code','website','profile','unhashed_password','members_count','country_id','business_est','fpp'] as $column) if (Schema::hasColumn('users',$column)) $table->dropColumn($column); }); }
};
