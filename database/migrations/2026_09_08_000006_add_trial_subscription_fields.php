<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('tenants')) {
            Schema::table('tenants', function (Blueprint $table) {
                if (!Schema::hasColumn('tenants', 'trial_starts_at')) $table->timestamp('trial_starts_at')->nullable();
                if (!Schema::hasColumn('tenants', 'trial_ends_at')) $table->timestamp('trial_ends_at')->nullable();
                if (!Schema::hasColumn('tenants', 'last_trial_reminder_at')) $table->timestamp('last_trial_reminder_at')->nullable();
                if (!Schema::hasColumn('tenants', 'subscription_status')) $table->enum('subscription_status', ['trial', 'active', 'expired', 'suspended'])->default('trial')->after('status');
            });
            if (Schema::hasColumn('tenants', 'trial_starts_at') && Schema::hasColumn('tenants', 'subscription_status')) {
                DB::table('tenants')->whereNull('trial_starts_at')->update(['subscription_status' => 'active', 'status' => 'active']);
            }
        }
        if (Schema::hasTable('admins')) {
            Schema::table('admins', function (Blueprint $table) {
                if (!Schema::hasColumn('admins', 'role_id') && Schema::hasTable('roles')) $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
                if (!Schema::hasColumn('admins', 'super_admin')) $table->boolean('super_admin')->default(false);
                if (!Schema::hasColumn('admins', 'email_verified_at')) $table->timestamp('email_verified_at')->nullable();
            });
        }
    }
    public function down(): void { Schema::table('tenants', function (Blueprint $table) { $table->dropColumn(['trial_starts_at','trial_ends_at','last_trial_reminder_at','subscription_status']); }); }
};
