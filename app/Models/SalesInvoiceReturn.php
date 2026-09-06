<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesInvoiceReturn extends BaseModel
{
    protected $guarded = ['id'];

    public function invoice()
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function items()
    {
        return $this->hasMany(SalesInvoiceReturnItem::class);
    }
     public function treasury()
    {
        return $this->belongsTo(Treasury::class);
    }
     public function shift()
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }
     public function cashier()
    {
        return $this->belongsTo(Employee::class, 'cashier_id');
    }
}
