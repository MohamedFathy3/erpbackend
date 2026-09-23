<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class CostCenter extends BaseModel
{
    protected $guarded=['id'];
    protected $casts=['is_active'=>'boolean'];
    public function parent(): BelongsTo { return $this->belongsTo(CostCenter::class,'parent_id'); }
    public function children(): HasMany { return $this->hasMany(CostCenter::class,'parent_id'); }
}
