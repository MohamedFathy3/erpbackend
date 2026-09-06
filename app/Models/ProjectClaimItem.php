<?php

namespace App\Models;

class ProjectClaimItem extends BaseModel
{
    protected $guarded = ['id'];
    protected $casts = ['quantity' => 'decimal:4', 'amount' => 'decimal:2'];

    public function claim() { return $this->belongsTo(ProjectClaim::class, 'project_claim_id'); }
    public function wbsItem() { return $this->belongsTo(ProjectWbsItem::class, 'project_wbs_item_id'); }
}
