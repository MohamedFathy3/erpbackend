<?php

namespace App\Models;

class WhatsappAppointmentRequest extends BaseModel
{
    protected $table = 'whatsapp_appointment_requests';
    protected $guarded = ['id', 'tenant_id'];
    protected $casts = [
        'resolved_at' => 'datetime',
        'scheduled_starts_at' => 'datetime',
        'scheduled_ends_at' => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(WhatsappConversation::class, 'conversation_id');
    }
}
