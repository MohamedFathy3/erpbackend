<?php

// app/Models/Size.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Size extends Model
{
    use BelongsToTenant;
    protected $fillable = [
        'name',
        'description',
        'code',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    // ✅ العلاقات
    public function products()
    {
        return $this->belongsToMany(Product::class, 'product_sizes');
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }
}
