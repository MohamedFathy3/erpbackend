<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    use BelongsToTenant;
    protected $guarded = ['id'];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
public function automotiveService()
{
    return $this->belongsTo(\App\Models\AutomotiveService::class, 'automotive_service_id');
}
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'product_unit_id');
    }

    public function color()
    {
        return $this->belongsTo(Color::class, 'color_id');
    }

}
