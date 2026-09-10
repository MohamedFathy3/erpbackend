<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class EmailLog extends BaseModel { protected $guarded=['id']; protected $casts=['sent_at'=>'datetime']; public function customer(): BelongsTo { return $this->belongsTo(Customer::class); } public function template(): BelongsTo { return $this->belongsTo(EmailTemplate::class,'template_id'); } }
