<?php

namespace App\Models;

class WhatsappAgentSetting extends BaseModel
{
    protected $table = 'whatsapp_agent_settings';
    protected $guarded = ['id', 'tenant_id'];
    protected $casts = [
        'is_enabled' => 'boolean',
        'enabled_actions' => 'array',
    ];

    public function account()
    {
        return $this->belongsTo(WhatsappAccount::class, 'whatsapp_account_id');
    }
}
