<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReturn extends BaseModel
{
    protected $guarded = ['id'];

    // ✅ أضف الأعمدة الجديدة في $fillable
    protected $fillable = [
        'purchase_invoices_id',
        'return_number',
        'total_amount',
        'paid_amount',        // ✅ جديد
        'payment_method',     // ✅ جديد
        'return_date',        // ✅ جديد
        'reason',
        'treasury_id',        // ✅ جديد
        'currency_id',        // ✅ جديد
        'warehouse_id',       // ✅ جديد
    ];

    public function items()
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    public function purchaseInvoice()
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoices_id');
    }

    public function treasury()
    {
        return $this->belongsTo(Treasury::class);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    // ✅ علاقة حركات الخزينة
    public function treasuryTransactions()
    {
        return $this->morphMany(TreasuryTransaction::class, 'reference');
    }
}