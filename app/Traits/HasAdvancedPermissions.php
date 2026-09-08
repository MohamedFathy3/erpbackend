<?php
namespace App\Traits;

use App\Models\Permission;

trait HasAdvancedPermissions
{
    public function hasPermission(string $permission): bool
    {
        if ($this->super_admin ?? false) return true;
        if (!$this->role_id || !method_exists($this, 'role')) return false;
        return $this->role?->permissions()->where('key', $permission)->exists() ?? false;
    }

    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) if ($this->hasPermission($permission)) return true;
        return false;
    }

    public function permissionKeys(): array
    {
        if ($this->super_admin ?? false) return Permission::query()->pluck('key')->all();
        return method_exists($this, 'role') ? ($this->role?->permissions()->pluck('key')->all() ?? []) : [];
    }
}
