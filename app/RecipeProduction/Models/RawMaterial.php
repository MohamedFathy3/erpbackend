<?php
namespace App\RecipeProduction\Models;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class RawMaterial extends BaseModel { protected $table='recipe_materials'; protected $guarded=['id']; protected $casts=['package_size'=>'decimal:6','package_cost'=>'decimal:6','unit_cost'=>'decimal:6','available_quantity'=>'decimal:6','minimum_stock_alert'=>'decimal:6','active'=>'boolean']; public function category(): BelongsTo { return $this->belongsTo(MaterialCategory::class,'category_id'); } public function recipeItems(): HasMany { return $this->hasMany(RecipeItem::class,'material_id'); } public function movements(): HasMany { return $this->hasMany(StockMovement::class,'material_id'); } }
