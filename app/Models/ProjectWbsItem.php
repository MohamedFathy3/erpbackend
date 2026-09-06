<?php
namespace App\Models;
class ProjectWbsItem extends BaseModel
{
    protected $guarded = ['id'];
    protected $casts = ['planned_quantity' => 'decimal:4', 'unit_price' => 'decimal:2', 'planned_cost' => 'decimal:2', 'completed_quantity' => 'decimal:4', 'completion_percent' => 'decimal:3'];
    public function project() { return $this->belongsTo(Project::class); }
    public function parent() { return $this->belongsTo(self::class, 'parent_id'); }
    public function children() { return $this->hasMany(self::class, 'parent_id'); }
    public function claimItems() { return $this->hasMany(ProjectClaimItem::class, 'project_wbs_item_id'); }
}
