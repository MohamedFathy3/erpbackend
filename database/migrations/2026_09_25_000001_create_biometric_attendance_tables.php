<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('biometric_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('name');
            $table->string('ip_address');
            $table->unsignedSmallInteger('port')->default(4370);
            $table->enum('protocol', ['tcp', 'udp'])->default('tcp');
            $table->unsignedInteger('device_password')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::table('employees', function (Blueprint $table) {
            if (!Schema::hasColumn('employees', 'biometric_user_id')) {
                $table->string('biometric_user_id')->nullable()->after('employee_code');
                $table->index(['tenant_id', 'biometric_user_id']);
            }
        });

        Schema::table('attendances', function (Blueprint $table) {
            if (!Schema::hasColumn('attendances', 'source')) $table->string('source')->default('manual')->after('status');
            if (!Schema::hasColumn('attendances', 'late_minutes')) $table->unsignedInteger('late_minutes')->default(0)->after('source');
            if (!Schema::hasColumn('attendances', 'worked_minutes')) $table->unsignedInteger('worked_minutes')->default(0)->after('late_minutes');
            if (!Schema::hasColumn('attendances', 'device_id')) $table->foreignId('device_id')->nullable()->after('employee_id')->constrained('biometric_devices')->nullOnDelete();
        });

        Schema::create('biometric_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('biometric_devices')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('device_user_id');
            $table->unsignedTinyInteger('state')->nullable();
            $table->dateTime('recorded_at');
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['device_id', 'device_user_id', 'recorded_at'], 'biometric_log_identity');
            $table->index(['tenant_id', 'recorded_at']);
        });

        Schema::create('attendance_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->time('work_start')->default('08:00');
            $table->time('work_end')->default('16:00');
            $table->unsignedInteger('grace_minutes')->default(15);
            $table->enum('late_deduction_type', ['none', 'fixed', 'hourly', 'percent'])->default('none');
            $table->decimal('late_deduction_value', 14, 4)->default(0);
            $table->enum('absence_deduction_type', ['none', 'fixed', 'daily', 'percent'])->default('none');
            $table->decimal('absence_deduction_value', 14, 4)->default(0);
            $table->boolean('deduct_early_leave')->default(false);
            $table->unsignedInteger('minimum_worked_minutes')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_rules');
        Schema::dropIfExists('biometric_logs');
        Schema::table('attendances', function (Blueprint $table) {
            foreach (['device_id', 'worked_minutes', 'late_minutes', 'source'] as $column) {
                if (Schema::hasColumn('attendances', $column)) $table->dropColumn($column);
            }
        });
        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'biometric_user_id')) $table->dropColumn('biometric_user_id');
        });
        Schema::dropIfExists('biometric_devices');
    }
};
