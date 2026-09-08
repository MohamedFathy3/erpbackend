<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends BaseModel
{
    protected $guarded = ['id'];
    protected $casts = ['created_at' => 'datetime', 'updated_at' => 'datetime'];

    public function modules(): HasMany { return $this->hasMany(TenantModule::class); }
    public function admins(): HasMany { return $this->hasMany(Admin::class); }
}
