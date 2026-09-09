<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reminder extends BaseModel
{
    protected $guarded = ['id'];
    protected $casts = ['remind_at' => 'datetime', 'notified_at' => 'datetime'];

    public function task(): BelongsTo { return $this->belongsTo(Task::class); }
}
