<?php
namespace App\RecipeProduction\Models;
use App\Models\BaseModel;
use App\Models\Product;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Recipe extends BaseModel { protected $table='product_recipes'; protected $guarded=['id']; protected $casts=['total_cost'=>'decimal:6']; public function product(): BelongsTo { return $this->belongsTo(Product::class); } public function items(): HasMany { return $this->hasMany(RecipeItem::class,'recipe_id'); } }
