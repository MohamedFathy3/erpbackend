<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Relations\HasMany;
class EmailTemplate extends BaseModel { protected $guarded=['id']; public function logs(): HasMany { return $this->hasMany(EmailLog::class,'template_id'); } }
