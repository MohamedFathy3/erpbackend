<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_transfer_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('to_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->string('status')->default('pending')->index();
            $table->foreignId('requested_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('note')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        $permissions = [
            ['inventory.transfer_requests.view', 'View inventory transfer requests', 'عرض طلبات نقل المخزون'],
            ['inventory.transfer_requests.create', 'Create inventory transfer requests', 'إنشاء طلبات نقل المخزون'],
            ['inventory.transfer_requests.approve', 'Approve inventory transfer requests', 'اعتماد طلبات نقل المخزون'],
        ];
        if (Schema::hasTable('permissions')) {
            $identifier = Schema::hasColumn('permissions', 'key') ? 'key' : 'slug';
            foreach ($permissions as [$key, $name, $nameAr]) {
                $payload = [
                    $identifier => $key,
                    'name' => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                if (Schema::hasColumn('permissions', 'name_ar')) $payload['name_ar'] = $nameAr;
                if (Schema::hasColumn('permissions', 'module')) $payload['module'] = 'inventory';
                if (Schema::hasColumn('permissions', 'description')) $payload['description'] = $nameAr;
                DB::table('permissions')->insertOrIgnore($payload);
            }
            $ids = DB::table('permissions')->whereIn($identifier, array_column($permissions, 0))->pluck('id', $identifier);
            $pivot = Schema::hasTable('role_permissions') ? 'role_permissions' : 'permission_role';
            foreach (DB::table('roles')->get() as $role) {
                $name = strtolower((string) $role->name);
                if (str_contains($name, 'admin') || str_contains($name, 'super') || str_contains($name, 'manager') || str_contains($name, 'cashier')) {
                    foreach ($ids as $id) {
                        DB::table($pivot)->insertOrIgnore(['permission_id' => $id, 'role_id' => $role->id]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_requests');
    }
};
