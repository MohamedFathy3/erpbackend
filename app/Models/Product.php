<?php

namespace App\Models;

use App\Traits\HasMedia;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class Product extends BaseModel
{
    use HasFactory, Notifiable, HasMedia;

    protected $with = [
        'media',
    ];

    protected $guarded = ['id'];

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return $query
            ->withoutGlobalScope('branch')
            ->where($field ?: $this->getRouteKeyName(), $value);
    }

    protected $casts = [
        'active' => 'boolean',
    ];

    /**
     * المنتجات القابلة للشراء/التخزين: يستبعد المنتجات المرتبطة بـ "خدمة" (مثل غسيل السيارة).
     * استخدمه في شاشات المشتريات/المخزون: Product::purchasable()->get()
     * شاشة POS لا تستخدمه لأن الخدمات يجب أن تظهر فيها.
     */
    public function scopePurchasable($query)
    {
        return $query->whereDoesntHave('automotiveService', function ($service) {
            $service->where('item_type', 'service');
        });
    }

    public function automotiveService()
    {
        return $this->hasOne(AutomotiveService::class, 'product_id');
    }

    public function units()
    {
        return $this->hasMany(ProductUnit::class);
    }

    public function offers()
    {
        return $this->belongsToMany(Offer::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function warehouses()
    {
        return $this->belongsToMany(Warehouse::class, 'product_warehouse')
            ->withPivot(['stock', 'cost'])
            ->withTimestamps();
    }

    public function branchPrices()
    {
        return $this->hasMany(ProductBranchPrice::class);
    }

    public function bomVersions() { return $this->hasMany(\App\Manufacturing\Models\BomVersion::class); }
    public function manufacturingOrders() { return $this->hasMany(\App\Manufacturing\Models\ManufacturingOrder::class); }

    public function purchaseInvoiceItems()
    {
        return $this->hasMany(PurchaseInvoiceItem::class);
    }

    public function invoiceItems()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function salesInvoiceItems()
    {
        return $this->hasMany(SalesInvoiceItem::class);
    }

    public function inventoryMovements()
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
