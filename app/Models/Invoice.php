<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends BaseModel
{
    protected $guarded = ['id'];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments()
    {
        return $this->hasMany(InvoicePayment::class);
    }

    public function returns()
    {
        return $this->hasMany(ReturnInvoice::class, 'invoice_id');
    }

    public function getReturnedAmountAttribute(): float
    {
        $returns = $this->relationLoaded('returns')
            ? $this->returns->reject(fn (ReturnInvoice $return) => $return->workflow_status === 'cancelled')
            : $this->returns()
                ->where(fn ($query) => $query->whereNull('workflow_status')->orWhere('workflow_status', '!=', 'cancelled'))
                ->get();

        return (float) $returns->sum('total_amount');
    }

    public function getRefundedAmountAttribute(): float
    {
        $returns = $this->relationLoaded('returns')
            ? $this->returns->reject(fn (ReturnInvoice $return) => $return->workflow_status === 'cancelled')
            : $this->returns()
                ->where(fn ($query) => $query->whereNull('workflow_status')->orWhere('workflow_status', '!=', 'cancelled'))
                ->get();

        return (float) $returns->sum('refunded_amount');
    }

    public function getNetAmountAttribute(): float
    {
        if ($this->is_complimentary) return 0;
        return max(0, (float) $this->total_amount - $this->returned_amount);
    }

    public function getNetPaidAmountAttribute(): float
    {
        if ($this->is_complimentary) return 0;
        return max(0, (float) $this->paid_amount - $this->refunded_amount);
    }

    public function getRemainingAmountAttribute(): float
    {
        return max(0, $this->net_amount - $this->net_paid_amount);
    }

    public function getNetCommissionAmountAttribute(): float
    {
        if ($this->is_complimentary) return 0;
        $originalTotal = (float) $this->total_amount;
        if ($originalTotal <= 0) return 0;

        $rate = $this->commission_rate_snapshot !== null
            ? (float) $this->commission_rate_snapshot
            : (float) ($this->salesRepresentative?->commission_rate ?? 0);
        $originalCommission = $this->commission_amount_snapshot !== null
            ? (float) $this->commission_amount_snapshot
            : round($originalTotal * $rate / 100, 2);

        return round($originalCommission * $this->net_amount / $originalTotal, 2);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function cogsJournalEntry()
    {
        return $this->belongsTo(JournalEntry::class, 'cogs_journal_entry_id');
    }

    public function commissionJournalEntry()
    {
        return $this->belongsTo(JournalEntry::class, 'commission_journal_entry_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesRepresentative()
    {
        return $this->belongsTo(SalesRepresentative::class, 'sales_representative_id');
    }

    public function shift()
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }
     public function cashier()
    {
        return $this->belongsTo(Employee::class, 'cashier_id');
    }

    // ✅ إضافة علاقة الخزينة
    public function treasury()
    {
        return $this->belongsTo(Treasury::class, 'treasury_id');
    }

    // ✅ إضافة علاقة حركات الخزينة
    public function treasuryTransactions()
    {
        return $this->morphMany(TreasuryTransaction::class, 'reference');
    }

}
