<?php

namespace App\Models;

class WhatsappMessage extends BaseModel
{
    protected $table = 'whatsapp_messages';

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'meta_response' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
