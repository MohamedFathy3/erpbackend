<?php

namespace App\Models;

class BiometricAgentPairingCode extends BaseModel
{
    protected $guarded = ['id'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];
}
