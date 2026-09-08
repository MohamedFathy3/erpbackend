<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends BaseModel
{
    protected $guarded = ['id'];
    protected $casts = ['converted_at' => 'datetime'];
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function assignee(): BelongsTo { return $this->belongsTo(Admin::class, 'assigned_to'); }
    public function deals(): HasMany { return $this->hasMany(Deal::class); }
    public function activities(): HasMany { return $this->hasMany(CrmActivity::class); }
}
