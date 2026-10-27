<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedInteger('grace_minutes')->default(15);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::table('employees', function (Blueprint $table): void {
            if (!Schema::hasColumn('employees', 'shift_id')) {
                $table->foreignId('shift_id')->nullable()->after('biometric_user_id')->constrained('shifts')->nullOnDelete();
                $table->index(['tenant_id', 'shift_id']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            if (Schema::hasColumn('employees', 'shift_id')) {
                $table->dropForeign(['shift_id']);
                $table->dropColumn('shift_id');
            }
        });
        Schema::dropIfExists('shifts');
    }
};
