<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends BaseModel
{
    protected $guarded = ['id'];
    protected $casts = ['due_date' => 'datetime'];

    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function relatedTo(): MorphTo { return $this->morphTo(); }
    public function reminders(): HasMany { return $this->hasMany(Reminder::class); }
    public function calendarEvent(): BelongsTo { return $this->belongsTo(CalendarEvent::class); }
}
