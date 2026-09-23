<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class AccountingDocument extends BaseModel { protected $guarded=['id']; protected $casts=['document_date'=>'date','amount'=>'decimal:2']; public function journalEntry(): BelongsTo{return $this->belongsTo(JournalEntry::class);} public function fiscalPeriod(): BelongsTo{return $this->belongsTo(FinancialPeriod::class);} public function treasury(): BelongsTo{return $this->belongsTo(Treasury::class);} public function sourceAccount(): BelongsTo{return $this->belongsTo(Account::class,'source_account_id');} public function destinationAccount(): BelongsTo{return $this->belongsTo(Account::class,'destination_account_id');} }
