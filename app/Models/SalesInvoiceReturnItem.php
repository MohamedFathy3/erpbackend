<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SalesInvoiceReturnItem extends Model
{
    use BelongsToTenant;
    protected $guarded = ['id'];

        public function return()
    {
        return $this->belongsTo(SalesInvoiceReturn::class, 'sales_invoice_return_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
public function color()
{
    return $this->belongsTo(Color::class);
}

public function unit()
{
    return $this->belongsTo(Unit::class, 'product_unit_id');
}
}
