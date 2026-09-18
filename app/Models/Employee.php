<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Traits\HasAdvancedPermissions;

class Employee extends BaseModel implements Authenticatable
{
    use AuthenticatableTrait, HasApiTokens, HasFactory, Notifiable, HasAdvancedPermissions;

    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    // ✅ إضافة علاقة الفرع
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    // ✅ إضافة علاقة الخزينة
    public function treasury()
    {
        return $this->belongsTo(Treasury::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'employee_permissions')->withTimestamps();
    }

}
