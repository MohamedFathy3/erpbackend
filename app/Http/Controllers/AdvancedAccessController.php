<?php
namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AdvancedAccessController extends Controller
{
   
public function permissions(Request $request) { abort_unless($this->canManage($request), 403); $column = Permission::identifierColumn(); $query = Permission::query()->orderBy($column); if (Schema::hasColumn('permissions', 'module')) $query->orderBy('module'); return response()->json(['data'=>$query->get()]); }
   
// public function roles(Request $request) { abort_unless($this->canManage($request), 403); return response()->json(['data'=>Role::with(['permissions', 'tenant'])->whereRaw("lower(name) not in ('admin', 'administrator', 'super admin', 'super administrator')")->orderBy('tenant_id')->orderBy('name')->get()]); }
   
public function roles(Request $request)
{
    abort_unless($this->canManage($request), 403);

    $user = $request->user();

    $query = Role::withoutGlobalScopes()
        ->with(['permissions', 'tenant'])
        ->whereRaw("LOWER(name) NOT IN (
            'admin',
            'administrator',
            'super admin',
            'super administrator'
        )");

    /*
    |--------------------------------------------------------------------------
    | Super Admin
    |--------------------------------------------------------------------------
    | Super Admin يستطيع رؤية Roles الخاصة بكل الـ Tenants.
    */
    if (!($user->super_admin ?? false)) {

        /*
        |--------------------------------------------------------------------------
        | Normal Admin
        |--------------------------------------------------------------------------
        | Admin يرى Roles الخاصة بالـ Tenant الخاص به فقط.
        |--------------------------------------------------------------------------
        */
        $tenantId = $user->tenant_id;

        abort_unless($tenantId, 403);

        $query->where('tenant_id', $tenantId);
    }

    $roles = $query
        ->orderBy('tenant_id')
        ->orderBy('name')
        ->get();

    return response()->json([
        'data' => $roles,
    ]);
}



    public function updateRole(Request $request, Role $role)
    {
        abort_unless($this->canManage($request), 403);
        $user = $request->user();

        if (!($user->super_admin ?? false)) {
            abort_unless($role->tenant_id === $user->tenant_id, 403, 'This role does not belong to your tenant.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'permissions' => ['array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);

        abort_unless(
            !Role::withoutGlobalScopes()
                ->where('tenant_id', $role->tenant_id)
                ->where('id', '!=', $role->id)
                ->whereRaw('LOWER(name) = ?', [strtolower(trim($data['name']))])
                ->exists(),
            422,
            'A role with this name already exists in the selected tenant.'
        );

        $role->update(['name' => trim($data['name'])]);
        $role->permissions()->sync($data['permissions'] ?? []);
        return response()->json(['data' => $role->fresh('permissions')]);
    }

        public function storeRole(Request $request)
        {
            abort_unless($this->canManage($request), 403);

            $user = $request->user();
            $data = $request->validate([
                'name' => ['required', 'string', 'max:100'],
                'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
                'permissions' => ['array'],
                'permissions.*' => ['integer', 'exists:permissions,id'],
            ]);

            $tenantId = (bool) ($user->super_admin ?? false)
                ? ($data['tenant_id'] ?? null)
                : $user->tenant_id;

            abort_unless($tenantId, 422, 'A tenant is required to create a role.');

            abort_unless(
                !Role::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->whereRaw('LOWER(name) = ?', [strtolower(trim($data['name']))])
                    ->exists(),
                422,
                'A role with this name already exists in the selected tenant.'
            );

            $role = Role::withoutGlobalScopes()->create([
                'name' => trim($data['name']),
                'tenant_id' => $tenantId,
            ]);
            $role->permissions()->sync($data['permissions'] ?? []);

            return response()->json([
                'data' => $role->fresh(['permissions', 'tenant']),
            ], 201);
        }
    public function mePermissions(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401);

        $permissionKeys = $user->permissionKeys();
        $column = Permission::identifierColumn();
        $permissionQuery = Permission::query()->whereIn($column, $permissionKeys);
        if (Schema::hasColumn('permissions', 'module')) {
            $permissionQuery->orderBy('module');
        }
        $permissionDetails = $permissionQuery->orderBy($column)->get()->map(static fn (Permission $permission): array => [
            'id' => $permission->id,
            'key' => $permission->{$column},
            'name' => $permission->name,
            'name_ar' => $permission->name_ar,
            'module' => $permission->module ?? 'general',
        ])->values();

        $role = $user->role ?? null;
        $branch = $user instanceof Employee ? $user->branch : null;

        return response()->json(['data' => [
            'is_super_admin' => (bool) ($user->super_admin ?? false),
            'role' => is_object($role) ? ($role->name ?? null) : (is_string($role) ? $role : null),
            'branch' => $branch?->only(['id', 'name', 'name_ar']),
            'permissions' => $permissionKeys,
            'permission_details' => $permissionDetails,
        ]]);
    }

    private function canManage(Request $request): bool
    {
        $user = $request->user();
        $role = strtolower((string) ($user?->role?->name ?? ''));
        return (bool) $user?->super_admin
            || $user instanceof \App\Models\Admin
            || $role === 'admin';
    }
}
