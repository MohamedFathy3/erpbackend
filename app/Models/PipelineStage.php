<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineStage extends BaseModel
{
    protected $guarded = ['id'];
    protected $casts = ['is_won' => 'boolean', 'is_lost' => 'boolean', 'sort_order' => 'integer'];
    public function deals(): HasMany { return $this->hasMany(Deal::class, 'stage_id'); }
}
