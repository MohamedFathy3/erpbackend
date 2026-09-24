<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class EmployeeFinancialTransaction extends BaseModel
{
    protected $guarded = ['id'];
    protected $casts = ['transaction_date' => 'date', 'amount' => 'decimal:2'];
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'journal_entry_id'); }
}
