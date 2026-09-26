<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class EmployeeBonus extends BaseModel
{
    protected $guarded = ['id'];
    protected $casts = ['bonus_date' => 'date', 'amount' => 'decimal:2', 'paid_at' => 'datetime'];
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function treasury(): BelongsTo { return $this->belongsTo(Treasury::class); }
    public function finance(): BelongsTo { return $this->belongsTo(Finance::class); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
}
