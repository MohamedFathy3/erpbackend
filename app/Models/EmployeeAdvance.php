<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class EmployeeAdvance extends BaseModel
{
    protected $guarded = ['id'];
    protected $casts = ['advance_date' => 'date', 'last_payment_at' => 'date', 'amount' => 'decimal:2', 'paid_amount' => 'decimal:2'];
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function treasury(): BelongsTo { return $this->belongsTo(Treasury::class); }
    public function finance(): BelongsTo { return $this->belongsTo(Finance::class); }
    public function payments() { return $this->hasMany(EmployeeAdvancePayment::class, 'advance_id'); }
    public function getRemainingAmountAttribute(): float { return max(0, (float)$this->amount - (float)$this->paid_amount); }
}
