<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Lead extends BaseModel { protected $guarded=['id']; public function assignedTo(): BelongsTo { return $this->belongsTo(Admin::class,'assigned_to'); } public function customer(): BelongsTo { return $this->belongsTo(Customer::class); } public function deals(): HasMany { return $this->hasMany(Deal::class); } public function activities(): HasMany { return $this->hasMany(CrmActivity::class); } }
