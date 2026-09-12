<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Schema;

class Permission extends Model
{
    protected $guarded = ['id'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }

    public static function identifierColumn(): string
    {
        return Schema::hasColumn('permissions', 'key') ? 'key' : 'slug';
    }

    public function getAttribute($key)
    {
        if ($key === 'key' && !array_key_exists('key', $this->getAttributes())) {
            return parent::getAttribute('slug');
        }
        if ($key === 'module' && !array_key_exists('module', $this->getAttributes())) {
            return 'general';
        }
        return parent::getAttribute($key);
    }
}
