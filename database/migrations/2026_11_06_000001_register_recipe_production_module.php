<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  if (!Schema::hasTable('tenants') || !Schema::hasTable('tenant_modules')) return;
  $now=now();
  foreach (DB::table('tenants')->pluck('id') as $tenantId) {
   DB::table('tenant_modules')->updateOrInsert(['tenant_id'=>$tenantId,'module_key'=>'recipe_production'],['is_enabled'=>true,'created_at'=>$now,'updated_at'=>$now]);
  }
 }
 public function down(): void { if (Schema::hasTable('tenant_modules')) DB::table('tenant_modules')->where('module_key','recipe_production')->delete(); }
};
