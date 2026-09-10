<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends BaseModel
{
    protected $guarded = ['id'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    public function hasPermission(string $permission): bool
    {
        return $this->relationLoaded('permissions')
            ? $this->permissions->contains('slug', $permission)
            : $this->permissions()->where('slug', $permission)->exists();
    }
}
