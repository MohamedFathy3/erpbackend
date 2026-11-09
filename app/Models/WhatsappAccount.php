<?php

namespace App\Models;

class WhatsappAccount extends BaseModel
{
    protected $table = 'whatsapp_accounts';

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['access_token', 'app_secret', 'webhook_verify_token'];

    protected $casts = [
        'access_token' => 'encrypted',
        'app_secret' => 'encrypted',
        'webhook_verify_token' => 'encrypted',
        'is_active' => 'boolean',
        'last_tested_at' => 'datetime',
    ];

    public function conversations()
    {
        return $this->hasMany(WhatsappConversation::class, 'whatsapp_account_id');
    }
}
