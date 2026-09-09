<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Relations\HasMany;
class PipelineStage extends BaseModel { protected $guarded=['id']; protected $casts=['is_won'=>'boolean','is_lost'=>'boolean']; public function deals(): HasMany { return $this->hasMany(Deal::class); } }
