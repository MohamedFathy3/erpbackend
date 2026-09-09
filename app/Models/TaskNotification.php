<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskNotification extends BaseModel
{
    protected $guarded = ['id'];
    protected $casts = ['is_read' => 'boolean', 'read_at' => 'datetime'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function task(): BelongsTo { return $this->belongsTo(Task::class); }
    public function reminder(): BelongsTo { return $this->belongsTo(Reminder::class); }
}
