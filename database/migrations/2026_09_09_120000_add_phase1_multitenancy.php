<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MODULES = [
        'crm', 'email', 'whatsapp', 'google_calendar', 'google_drive', 'tasks',
        'manufacturing', 'inventory', 'sales', 'purchasing', 'finance', 'hr',
        'reports', 'projects', 'workflow',
    ];

    private const TENANT_TABLES = [
        'admins', 'branches', 'banks', 'categories', 'colors', 'currencies', 'customers',
        'employees', 'invoices', 'inventory_logs', 'inventory_movements', 'inventory_variant_stocks',
        'loyalty_settings', 'manufacturing_boms', 'manufacturing_bom_items', 'manufacturing_cost_entries',
        'manufacturing_orders', 'manufacturing_order_operations', 'manufacturing_quality_inspections',
        'manufacturing_work_centers', 'offers', 'products', 'product_units', 'product_unit_colors',
        'projects', 'project_claims', 'project_claim_items', 'project_cost_entries', 'project_wbs_items',
        'purchase_invoices', 'purchase_invoice_items', 'purchase_orders', 'purchase_order_items',
        'purchase_returns', 'purchase_return_items', 'revenues', 'sales_invoices', 'sales_invoice_items',
        'sales_invoice_returns', 'sales_invoice_return_items', 'sales_representatives', 'suppliers',
        'taxes', 'treasuries', 'treasury_transactions', 'transfers', 'units', 'warehouses',
    ];

    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('domain')->nullable()->unique();
            $table->enum('status', ['active', 'suspended', 'trial', 'expired'])->default('active');
            $table->string('plan')->default('default');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tenant_modules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('module_key');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'module_key']);
        });

        DB::table('tenants')->insert([
            'name' => 'Default Tenant',
            'slug' => 'default',
            'status' => 'active',
            'plan' => 'default',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $tenantId = (int) DB::table('tenants')->where('slug', 'default')->value('id');

        if (Schema::hasTable('admins') && !Schema::hasColumn('admins', 'super_admin')) {
            Schema::table('admins', function (Blueprint $table): void {
                $table->boolean('super_admin')->default(false)->index();
            });
        }

        foreach (self::TENANT_TABLES as $tableName) {
            if (!Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'tenant_id')) continue;
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('tenant_id')->nullable()->index();
            });
            DB::table($tableName)->whereNull('tenant_id')->where(function ($query) use ($tableName): void {
                if ($tableName === 'admins' && Schema::hasColumn('admins', 'super_admin')) {
                    $query->where('super_admin', false);
                }
            })->update(['tenant_id' => $tenantId]);
        }

        DB::table('tenant_modules')->insert(array_map(
            fn (string $module): array => [
                'tenant_id' => $tenantId,
                'module_key' => $module,
                'is_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            self::MODULES,
        ));
    }

    public function down(): void
    {
        foreach (self::TENANT_TABLES as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'tenant_id')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropColumn('tenant_id');
                });
            }
        }
        Schema::dropIfExists('tenant_modules');
        Schema::dropIfExists('tenants');
    }
};
