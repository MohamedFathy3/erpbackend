<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Relations\HasMany;
class FinancialPeriod extends BaseModel
{
    protected $guarded=['id'];
    protected $casts=['starts_on'=>'date','ends_on'=>'date','is_current'=>'boolean','closed_at'=>'datetime'];
    public function journalEntries(): HasMany { return $this->hasMany(JournalEntry::class,'fiscal_period_id'); }
    public function isOpen(): bool { return $this->status === 'open'; }
}
