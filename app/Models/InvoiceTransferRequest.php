<?php
namespace App\Models;
use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class InvoiceTransferRequest extends BaseModel { protected $guarded=['id']; public function fromEmployee(): BelongsTo{return $this->belongsTo(Employee::class,'from_employee_id');} public function toEmployee(): BelongsTo{return $this->belongsTo(Employee::class,'to_employee_id');} public function shift(): BelongsTo{return $this->belongsTo(CashierShift::class,'cashier_shift_id');} public function approver(): BelongsTo{return $this->belongsTo(Admin::class,'approved_by');} public function journalEntry(): BelongsTo{return $this->belongsTo(JournalEntry::class,'journal_entry_id');} }
