<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailLog extends BaseModel
{
    protected $table = 'email_logs';
    protected $guarded = ['id'];
    protected $casts = ['sent_at' => 'datetime', 'meta' => 'array'];
    public function template(): BelongsTo { return $this->belongsTo(EmailTemplate::class, 'template_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
}
