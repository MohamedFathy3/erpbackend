<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class CrmActivity extends BaseModel { protected $table='crm_activities'; protected $guarded=['id']; protected $casts=['occurred_at'=>'datetime']; public function lead(): BelongsTo { return $this->belongsTo(Lead::class); } public function deal(): BelongsTo { return $this->belongsTo(Deal::class); } public function customer(): BelongsTo { return $this->belongsTo(Customer::class); } public function creator(): BelongsTo { return $this->belongsTo(Admin::class,'created_by'); } }
