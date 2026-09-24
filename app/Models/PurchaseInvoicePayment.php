<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PurchaseInvoicePayment extends BaseModel { protected $guarded=['id']; protected $casts=['payment_date'=>'date','amount'=>'decimal:2']; public function invoice():BelongsTo{return $this->belongsTo(PurchaseInvoice::class,'purchase_invoice_id');} public function treasury():BelongsTo{return $this->belongsTo(Treasury::class);} public function journalEntry():BelongsTo{return $this->belongsTo(JournalEntry::class);} }
