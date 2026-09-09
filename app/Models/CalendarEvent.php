<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEvent extends BaseModel
{
    protected $table = 'calendar_events';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'google_payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GoogleConnection::class, 'google_connection_id');
    }
}
