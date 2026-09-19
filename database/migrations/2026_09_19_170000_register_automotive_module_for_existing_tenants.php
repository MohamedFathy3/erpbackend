<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tenants') || !Schema::hasTable('tenant_modules')) return;
        $now = now();
        DB::table('tenants')->select('id')->orderBy('id')->chunkById(100, function ($tenants) use ($now): void {
            foreach ($tenants as $tenant) {
                DB::table('tenant_modules')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'module_key' => 'automotive_service'],
                    ['is_enabled' => true, 'created_at' => $now, 'updated_at' => $now]
                );
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('tenant_modules')) {
            DB::table('tenant_modules')->where('module_key', 'automotive_service')->delete();
        }
    }
};
