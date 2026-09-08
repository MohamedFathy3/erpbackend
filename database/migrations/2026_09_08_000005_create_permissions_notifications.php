<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('permissions', function (Blueprint $table) { $table->id(); $table->string('key')->unique(); $table->string('name'); $table->string('name_ar')->nullable(); $table->string('module')->index(); $table->timestamps(); });
        Schema::create('permission_role', function (Blueprint $table) { $table->foreignId('permission_id')->constrained()->cascadeOnDelete(); $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete(); $table->primary(['permission_id','role_id']); });
        if (!Schema::hasTable('notifications')) Schema::create('notifications', function (Blueprint $table) { $table->uuid('id')->primary(); $table->string('type'); $table->morphs('notifiable'); $table->text('data'); $table->timestamp('read_at')->nullable(); $table->timestamps(); $table->index(['notifiable_type','notifiable_id','read_at']); });
        $permissions = [
            ['crm.view','View CRM','عرض CRM','crm'], ['crm.manage_leads','Manage Leads','إدارة العملاء المحتملين','crm'], ['crm.manage_deals','Manage Deals','إدارة الصفقات','crm'], ['crm.view_reports','View CRM Reports','عرض تقارير CRM','crm'], ['crm.send_email','Send CRM Email','إرسال بريد CRM','crm'], ['users.view','View Users','عرض المستخدمين','users'], ['users.manage','Manage Users','إدارة المستخدمين','users'], ['roles.manage','Manage Roles','إدارة الأدوار والصلاحيات','users'], ['notifications.view','View Notifications','عرض الإشعارات','system'], ['settings.manage','Manage Settings','إدارة الإعدادات','system'],
        ];
        foreach ($permissions as $permission) DB::table('permissions')->insertOrIgnore(['key'=>$permission[0],'name'=>$permission[1],'name_ar'=>$permission[2],'module'=>$permission[3],'created_at'=>now(),'updated_at'=>now()]);
        $all = DB::table('permissions')->pluck('id','key');
        foreach (DB::table('roles')->get() as $role) {
            $name = strtolower($role->name);
            if (str_contains($name, 'super') || str_contains($name, 'admin')) $keys = array_keys($all->toArray());
            elseif (str_contains($name, 'sales')) $keys = ['crm.view','crm.manage_leads','crm.manage_deals','crm.send_email','notifications.view'];
            elseif (str_contains($name, 'manager')) $keys = ['crm.view','crm.manage_leads','crm.manage_deals','crm.view_reports','crm.send_email','notifications.view'];
            elseif (str_contains($name, 'viewer')) $keys = ['crm.view','crm.view_reports','notifications.view'];
            else $keys = ['notifications.view'];
            foreach ($keys as $key) DB::table('permission_role')->insertOrIgnore(['permission_id'=>$all[$key], 'role_id'=>$role->id]);
        }
    }
    public function down(): void { Schema::dropIfExists('notifications'); Schema::dropIfExists('permission_role'); Schema::dropIfExists('permissions'); }
};
