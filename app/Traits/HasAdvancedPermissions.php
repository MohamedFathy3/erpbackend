<?php
namespace App\Traits;

use App\Models\Permission;

trait HasAdvancedPermissions
{
    public function hasPermission(string $permission): bool
    {
        if ($this->super_admin ?? false) return true;

        $roleName = strtolower((string) ($this->role?->name ?? ''));
        if (in_array($roleName, ['admin', 'administrator', 'tenant_admin', 'company_admin'], true)) {
            return true;
        }

        if (method_exists($this, 'permissions') && $this->permissions()->where(Permission::identifierColumn(), $permission)->exists()) {
            return true;
        }

        return $this->role?->permissions()->where(Permission::identifierColumn(), $permission)->exists() ?? false;
    }

    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) if ($this->hasPermission($permission)) return true;
        return false;
    }

    public function permissionKeys(): array
    {
        $column = Permission::identifierColumn();
        if (($this->super_admin ?? false) || in_array(strtolower((string) ($this->role?->name ?? '')), ['admin', 'administrator', 'tenant_admin', 'company_admin'], true)) {
            return Permission::query()->pluck($column)->all();
        }

        $rolePermissions = method_exists($this, 'role')
            ? ($this->role?->permissions()->pluck($column) ?? collect())
            : collect();
        $directPermissions = method_exists($this, 'permissions')
            ? $this->permissions()->pluck($column)
            : collect();

        return $rolePermissions->merge($directPermissions)->unique()->values()->all();
    }
}
