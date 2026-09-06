<?php
namespace App\Models;
class Project extends BaseModel
{
    protected $guarded = ['id'];
    protected $casts = ['start_date' => 'date', 'planned_end_date' => 'date', 'actual_end_date' => 'date', 'contract_value' => 'decimal:2', 'budget_cost' => 'decimal:2', 'actual_cost' => 'decimal:2', 'retention_percent' => 'decimal:3'];
    public function customer() { return $this->belongsTo(Customer::class); }
    public function wbsItems() { return $this->hasMany(ProjectWbsItem::class); }
    public function claims() { return $this->hasMany(ProjectClaim::class); }
}
