<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends BaseModel
{
    protected $guarded = ['id'];
    public function permissions(): BelongsToMany { return $this->belongsToMany(Permission::class, 'permission_role'); }
}
