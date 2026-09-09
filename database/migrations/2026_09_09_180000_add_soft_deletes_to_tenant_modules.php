<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('tenant_modules') && !Schema::hasColumn('tenant_modules', 'deleted_at')) {
            Schema::table('tenant_modules', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tenant_modules') && Schema::hasColumn('tenant_modules', 'deleted_at')) {
            Schema::table('tenant_modules', function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }
};
