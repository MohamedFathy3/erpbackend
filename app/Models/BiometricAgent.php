<?php

namespace App\Models;

class BiometricAgent extends BaseModel
{
    protected $guarded = ['id'];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}
