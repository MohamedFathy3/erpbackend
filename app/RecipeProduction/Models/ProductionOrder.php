<?php
namespace App\RecipeProduction\Models;
use App\Models\BaseModel;
use App\Models\Product;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class ProductionOrder extends BaseModel { protected $table='recipe_production_orders'; protected $guarded=['id']; protected $casts=['planned_quantity'=>'decimal:6','produced_quantity'=>'decimal:6','expected_cost'=>'decimal:6','actual_cost'=>'decimal:6','actual_waste'=>'decimal:6','confirmed_at'=>'datetime']; public function recipe(): BelongsTo { return $this->belongsTo(Recipe::class,'recipe_id'); } public function product(): BelongsTo { return $this->belongsTo(Product::class); } public function items(): HasMany { return $this->hasMany(ProductionOrderItem::class,'production_order_id'); } }
