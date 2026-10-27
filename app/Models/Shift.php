<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends BaseModel
{
    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function crossesMidnight(): bool
    {
        return $this->end_time <= $this->start_time;
    }
}
