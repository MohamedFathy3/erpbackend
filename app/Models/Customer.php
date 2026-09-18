<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Customer extends BaseModel
{

    protected $guarded = ['id'];

    protected $casts = [
        'active' => 'boolean',
        'whatsapp_last_inbound_at' => 'datetime',
    ];

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function salesInvoices()
    {
        return $this->hasMany(SalesInvoice::class);
    }

    public function salesReturns()
    {
        return $this->hasManyThrough(
            SalesInvoiceReturn::class,
            SalesInvoice::class,
            'customer_id',
            'sales_invoice_id',
            'id',
            'id'
        );
    }

    public function getOutstandingBalanceAttribute(): float
    {
        return (float) $this->invoices()->sum('remaining_amount')
            + (float) $this->salesInvoices()->sum(DB::raw('net_total - paid_amount'))
            - (float) $this->salesReturns()->sum('sales_invoice_returns.total_amount');
    }

    // مجموع كل الفواتير
    public function getTotalInvoicesAmountAttribute()
    {
        return $this->invoices()->sum('total_amount');
    }

    // النقاط الحالية (اليدوية + المكتسبة)
    public function getLoyaltyPointsAttribute()
    {
        return (int) ($this->point ?? 0);
    }
}
