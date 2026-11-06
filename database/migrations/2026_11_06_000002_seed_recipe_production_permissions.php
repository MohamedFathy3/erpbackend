<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  if (!Schema::hasTable('permissions')) return;
  $now=now();
  foreach (['view','create','update','delete','confirm'] as $action) {
   $key="recipe_production.$action";
   $where=Schema::hasColumn('permissions','key')?['key'=>$key]:['slug'=>$key];
   $values=[];
   if(Schema::hasColumn('permissions','name'))$values['name']=ucfirst($action).' Recipe Production';
   if(Schema::hasColumn('permissions','slug')&&!Schema::hasColumn('permissions','key'))$values['slug']=$key;
   if(Schema::hasColumn('permissions','name_ar'))$values['name_ar']='إدارة التركيبات والإنتاج';
   if(Schema::hasColumn('permissions','module'))$values['module']='recipe_production';
   if(Schema::hasColumn('permissions','created_at'))$values['created_at']=$now;
   if(Schema::hasColumn('permissions','updated_at'))$values['updated_at']=$now;
   DB::table('permissions')->updateOrInsert($where,$values);
  }
 }
 public function down(): void { if(!Schema::hasTable('permissions'))return; $column=Schema::hasColumn('permissions','key')?'key':'slug'; DB::table('permissions')->where($column,'like','recipe_production.%')->delete(); }
};
