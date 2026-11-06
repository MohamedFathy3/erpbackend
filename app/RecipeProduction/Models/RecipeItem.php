<?php
namespace App\RecipeProduction\Models;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class RecipeItem extends BaseModel { protected $table='product_recipe_items'; public $timestamps=true; protected $guarded=['id']; protected $casts=['required_quantity'=>'decimal:6','waste_quantity'=>'decimal:6','unit_cost'=>'decimal:6','total_cost'=>'decimal:6']; public function recipe(): BelongsTo { return $this->belongsTo(Recipe::class,'recipe_id'); } public function material(): BelongsTo { return $this->belongsTo(RawMaterial::class,'material_id'); } }
