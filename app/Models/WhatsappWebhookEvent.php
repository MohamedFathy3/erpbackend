<?php

namespace App\Models;

class WhatsappWebhookEvent extends BaseModel
{
    protected $table = 'whatsapp_webhook_events';

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];
}
