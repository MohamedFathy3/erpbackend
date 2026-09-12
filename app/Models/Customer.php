<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
            + (float) $this->salesInvoices()->sum('net_total')
            - (float) $this->salesReturns()->sum('total_amount');
    }

    // مجموع كل الفواتير
    public function getTotalInvoicesAmountAttribute()
    {
        return $this->invoices()->sum('total_amount');
    }

    // النقاط الحالية (اليدوية + المكتسبة)
    public function getLoyaltyPointsAttribute()
    {
        $loyaltySetting = LoyaltySetting::first();
        if (!$loyaltySetting || $loyaltySetting->point_value <= 0) return $this->point ?? 0;

        $earnedPoints = floor($this->total_invoices_amount / $loyaltySetting->point_value);

        return ($this->point ?? 0) + $earnedPoints;
    }
}
