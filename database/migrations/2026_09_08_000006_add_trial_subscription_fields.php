<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        Schema::table('tenants', function (Blueprint $table) { $table->timestamp('trial_starts_at')->nullable(); $table->timestamp('trial_ends_at')->nullable(); $table->timestamp('last_trial_reminder_at')->nullable(); $table->enum('subscription_status',['trial','active','expired','suspended'])->default('trial')->after('status'); });
        Schema::table('admins', function (Blueprint $table) { if (!Schema::hasColumn('admins','role_id')) $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete(); if (!Schema::hasColumn('admins','super_admin')) $table->boolean('super_admin')->default(false); if (!Schema::hasColumn('admins','email_verified_at')) $table->timestamp('email_verified_at')->nullable(); });
        DB::table('tenants')->whereNull('trial_starts_at')->update(['subscription_status'=>'active','status'=>'active']);
    }
    public function down(): void { Schema::table('tenants', function (Blueprint $table) { $table->dropColumn(['trial_starts_at','trial_ends_at','last_trial_reminder_at','subscription_status']); }); }
};
