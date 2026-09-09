<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Google connections belong to the authenticated ERP account. The ERP
        // uses the admins table for that account, while the original migration
        // incorrectly added a foreign key to the legacy users table.
        foreach (['google_connections', 'calendar_events'] as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                try {
                    $table->dropForeign([$tableName === 'google_connections' ? 'user_id' : 'user_id']);
                } catch (\Throwable) {
                    // The constraint may already have been removed on a fresh install.
                }
            });
        }
    }

    public function down(): void
    {
        // Deliberately leave the columns unconstrained: admins and users are
        // separate authentication tables in this application.
    }
};
