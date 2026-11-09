<?php

namespace App\Models;

class WhatsappConversationMessage extends BaseModel
{
    protected $table = 'whatsapp_conversation_messages';

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'provider_timestamp' => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(WhatsappConversation::class, 'conversation_id');
    }
}
