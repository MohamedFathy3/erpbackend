<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\Branch;
use App\Models\Warehouse;
use App\Models\Treasury;
use App\Models\Currency;
use App\Models\Tax;
use App\Models\Category;
use App\Models\Color;
use App\Models\Size;
use App\Models\Unit;
use App\Models\Product;
use App\Models\ProductUnit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SuperAdminTenantController extends Controller
{
    private const MODULES = [
        'dashboard', 'pos', 'inventory', 'purchasing', 'sales', 'finance',
        'hr', 'crm', 'reports', 'employee_financial_reports', 'warehouse_reports',
        'settings', 'industries', 'manufacturing', 'manufacturing_setup',
        'product_ledger', 'inventory_transfer_requests', 'representative',
        'projects', 'workflow', 'access_control', 'ai_assistant',
        'automotive_service',
        'email', 'whatsapp', 'google_calendar', 'google_drive', 'tasks',
    ];

    public function index()
    {
        return $this->tenants();
    }

    public function store(Request $request)
    {
        abort_unless((bool) auth()->user()?->super_admin, 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:100', 'alpha_dash', 'unique:tenants,slug'],
            'status' => ['nullable', 'in:active,suspended,trial,expired'],
            'plan' => ['nullable', 'string', 'max:50'],
            'trial_ends_at' => ['nullable', 'date'],
            'admin_name' => ['nullable', 'string', 'max:255'],
            'admin_email' => ['nullable', 'email', 'unique:admins,email'],
            'admin_password' => ['nullable', 'string', 'min:8'],
        ]);

        if (($data['admin_email'] ?? null) && empty($data['admin_password'])) {
            return response()->json(['message' => 'admin_password is required when admin_email is provided.'], 422);
        }

        $result = DB::transaction(function () use ($data): array {
            $slug = $data['slug'] ?? Str::slug($data['name']);
            if ($slug === '') $slug = 'workspace-' . Str::lower(Str::random(6));

            $tenant = Tenant::withoutGlobalScopes()->create([
                'name' => $data['name'],
                'slug' => $slug,
                'status' => $data['status'] ?? 'active',
                'plan' => $data['plan'] ?? 'starter',
                'trial_ends_at' => $data['trial_ends_at'] ?? null,
                'subscription_status' => match ($data['status'] ?? 'active') {
                    'trial' => 'trial',
                    'suspended' => 'suspended',
                    'expired' => 'expired',
                    default => 'active',
                },
            ]);

            foreach (self::MODULES as $moduleKey) {
                TenantModule::withoutGlobalScopes()->create([
                    'tenant_id' => $tenant->id,
                    'module_key' => $moduleKey,
                    'is_enabled' => true,
                ]);
            }

          $roles = $this->createDefaultRolesForTenant($tenant);
          $this->createDefaultTenantData($tenant);

$admin = null;

if (!empty($data['admin_email'])) {
    $admin = Admin::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'role_id' => $roles['Admin']->id,
        'name' => $data['admin_name'] ?? $data['admin_email'],
        'email' => $data['admin_email'],
        'password' => Hash::make($data['admin_password']),
        'active' => true,
        'super_admin' => false,
    ]);
}

            return [$tenant, $admin];
        });

        [$tenant, $admin] = $result;
        return response()->json([
            'data' => [
                'tenant' => $tenant,
                'admin' => $admin,
            ],
        ], 201);
    }

    public function tenants()
    {
        abort_unless((bool) auth()->user()?->super_admin, 403);
        return response()->json([
            'data' => Tenant::query()->withCount('modules')->withCount('admins')->latest()->get(),
        ]);
    }

    public function updateStatus(Request $request, Tenant $tenant)
    {
        abort_unless((bool) auth()->user()?->super_admin, 403);
        $data = $request->validate([
            'status' => ['required', 'in:active,suspended,trial,expired'],
        ]);

        $subscriptionStatus = match ($data['status']) {
            'trial' => 'trial',
            'suspended' => 'suspended',
            'expired' => 'expired',
            default => 'active',
        };

        $tenant->update([
            'status' => $data['status'],
            'subscription_status' => $subscriptionStatus,
        ]);

        if ($data['status'] === 'suspended') {
            Admin::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get()->each(fn (Admin $admin) => $admin->tokens()->delete());
            Employee::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get()->each(fn (Employee $employee) => $employee->tokens()->delete());
        }

        activity()->causedBy(auth()->user())->performedOn($tenant)->withProperties($data)->log('tenant status updated');
        return response()->json(['data' => $tenant->fresh()]);
    }

    public function modules(Tenant $tenant)
    {
        abort_unless((bool) auth()->user()?->super_admin, 403);
        $modules = collect(self::MODULES)->map(function (string $key) use ($tenant): array {
            $record = TenantModule::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('module_key', $key)
                ->first();
            return ['module_key' => $key, 'is_enabled' => $record?->is_enabled ?? true];
        });
        return response()->json(['data' => $modules]);
    }

    public function updateModule(Request $request, Tenant $tenant, string $moduleKey)
    {
        abort_unless((bool) auth()->user()?->super_admin, 403);
        $validated = $request->validate([
            'is_enabled' => ['required', 'boolean'],
        ]);
        abort_unless(in_array($moduleKey, self::MODULES, true), 404);
        $module = TenantModule::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'module_key' => $moduleKey],
            ['is_enabled' => $validated['is_enabled']],
        );
        activity()->performedOn($module)->withProperties($validated)->log('tenant module updated');
        return response()->json(['data' => $module]);
    }

    public function enabledModules()
    {
        $user = auth()->user();
        abort_unless($user, 401);
        if ((bool) ($user->super_admin ?? false)) {
            return response()->json(['data' => self::MODULES]);
        }
        $configured = TenantModule::query()
            ->where('tenant_id', $user->tenant_id)
            ->pluck('is_enabled', 'module_key');
        return response()->json([
            'data' => collect(self::MODULES)
                ->filter(fn (string $key): bool => !array_key_exists($key, $configured->all()) || (bool) $configured[$key])
                ->values(),
        ]);
    }

private function createDefaultRolesForTenant(Tenant $tenant): array
{
    $roleNames = [
        'Admin',
        'Manager',
        'Sales',
        'Purchasing',
        'Accountant',
        'Cashier',
        'Warehouse',
        'HR',
        'Customer Support',
        'Viewer',
    ];

    $roles = [];

    $permissions = \App\Models\Permission::query()->pluck('id');

    foreach ($roleNames as $name) {
        $role = \App\Models\Role::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
        ]);

        // Admin gets all permissions
        if ($name === 'Admin') {
            $role->permissions()->sync($permissions);
        }

        $roles[$name] = $role;
    }

    return $roles;
}

    private function createDefaultTenantData(Tenant $tenant): void
    {
        $branch = Branch::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'MAIN'],
            [
                'name' => 'الفرع الرئيسي',
                'phone' => null,
                'address' => null,
                'manager' => null,
                'active' => true,
                'main_branch' => true,
            ],
        );

        $warehouse = Warehouse::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'MAIN'],
            [
                'branch_id' => $branch->id,
                'name' => 'المخزن الرئيسي',
                'phone' => null,
                'address' => null,
                'manager' => null,
                'note' => null,
                'active' => true,
                'main_branch' => true,
            ],
        );

        $currency = Currency::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'EGP'],
            [
                'name' => 'جنيه مصري',
                'symbol' => 'ج.م',
                'exchange_rate' => '1',
                'active' => true,
                'default' => true,
            ],
        );

        Treasury::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'MAIN-TREASURY'],
            [
                'branch_id' => $branch->id,
                'name' => 'الخزينة الرئيسية',
                'balance' => 0,
                'currency' => 'EGP',
                'is_main' => true,
                'currencies' => [
                    [
                        'currency_id' => $currency->id,
                        'balance' => 0,
                        'is_main' => true,
                    ],
                ],
                'notes' => 'تم إنشاء الخزينة الرئيسية تلقائيا عند إنشاء نسخة الشركة.',
            ],
        );

        Tax::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'ضريبة صفرية'],
            [
                'rate' => '0',
                'active' => true,
                'default' => true,
            ],
        );

        $category = Category::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'عام'],
            ['parent_id' => null, 'icon' => null],
        );

        Color::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'DEFAULT'],
            [
                'name' => 'افتراضي',
                'hex_code' => '#FFFFFF',
            ],
        );

        Size::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'FREE'],
            [
                'name' => 'مقاس افتراضي',
                'description' => 'مقاس افتراضي عام',
                'sort_order' => 0,
                'is_active' => true,
            ],
        );

        $unit = Unit::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'PCS'],
            [
                'name' => 'قطعة',
                'type_unit' => 'count',
                'position' => 'single',
            ],
        );

        $product = Product::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'sku' => 'DEFAULT'],
            [
                'name' => 'المنتج الأساسي',
                'description' => 'منتج افتراضي تم إنشاؤه تلقائيا عند إنشاء نسخة الشركة.',
                'barcode' => null,
                'price' => 0,
                'cost' => 0,
                'stock' => 0,
                'reorder_level' => 0,
                'category_id' => $category->id,
                'beginning_balance' => false,
                'active' => true,
                'product_type' => 'good',
                'stock_unit' => 'pcs',
                'base_unit' => 'pcs',
                'conversion_factor' => 1,
                'tracking' => 'none',
                'package_size' => 1,
                'package_unit' => 'pcs',
                'package_cost' => 0,
            ],
        );

        ProductUnit::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'product_id' => $product->id, 'unit_id' => $unit->id],
            [
                'cost_price' => 0,
                'sell_price' => 0,
            ],
        );

        DB::table('product_warehouse')->updateOrInsert(
            [
                'tenant_id' => $tenant->id,
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
            ],
            [
                'stock' => 0,
                'cost' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}
