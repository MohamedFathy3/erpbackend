<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('biometric_devices', function (Blueprint $table): void {
            if (!Schema::hasColumn('biometric_devices', 'serial_number')) {
                $table->string('serial_number', 64)->nullable()->after('name');
                $table->index(['tenant_id', 'serial_number']);
            }
            if (!Schema::hasColumn('biometric_devices', 'communication_mode')) {
                $table->string('communication_mode', 20)->default('pull')->after('protocol');
            }
            if (!Schema::hasColumn('biometric_devices', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable()->after('last_synced_at');
            }
            if (!Schema::hasColumn('biometric_devices', 'adms_registered_at')) {
                $table->timestamp('adms_registered_at')->nullable()->after('last_seen_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('biometric_devices', function (Blueprint $table): void {
            foreach (['adms_registered_at', 'last_seen_at', 'communication_mode', 'serial_number'] as $column) {
                if (Schema::hasColumn('biometric_devices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
