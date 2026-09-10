<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Deal extends BaseModel { protected $guarded=['id']; protected $casts=['value'=>'decimal:2','expected_close_date'=>'date']; public function stage(): BelongsTo { return $this->belongsTo(PipelineStage::class,'pipeline_stage_id'); } public function lead(): BelongsTo { return $this->belongsTo(Lead::class); } public function customer(): BelongsTo { return $this->belongsTo(Customer::class); } public function assignedTo(): BelongsTo { return $this->belongsTo(Admin::class,'assigned_to'); } public function activities(): HasMany { return $this->hasMany(CrmActivity::class); } }
