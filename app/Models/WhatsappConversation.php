<?php

namespace App\Models;

class WhatsappConversation extends BaseModel
{
    protected $table = 'whatsapp_conversations';

    protected $guarded = ['id'];

    protected $casts = ['last_inbound_at' => 'datetime'];

    public function account()
    {
        return $this->belongsTo(WhatsappAccount::class, 'whatsapp_account_id');
    }

    public function messages()
    {
        return $this->hasMany(WhatsappConversationMessage::class, 'conversation_id');
    }
}
