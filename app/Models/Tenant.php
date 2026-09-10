<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends BaseModel
{
    protected $guarded = ['id'];

    protected $casts = [
        'trial_ends_at' => 'datetime',
    ];

    public function admins(): HasMany
    {
        return $this->hasMany(Admin::class);
    }

    public function modules(): HasMany
    {
        return $this->hasMany(TenantModule::class);
    }

    public function enabledModules(): HasMany
    {
        return $this->modules()->where('is_enabled', true);
    }
}
