<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class EmployeeAdvancePayment extends BaseModel
{
    protected $guarded = ['id'];
    protected $casts = ['payment_date' => 'date', 'amount' => 'decimal:2'];
    public function advance(): BelongsTo { return $this->belongsTo(EmployeeAdvance::class, 'advance_id'); }
}
