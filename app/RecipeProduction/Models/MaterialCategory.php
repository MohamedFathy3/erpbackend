<?php
namespace App\RecipeProduction\Models;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
class MaterialCategory extends BaseModel { protected $table='recipe_material_categories'; protected $guarded=['id']; protected $casts=['active'=>'boolean']; public function materials(): HasMany { return $this->hasMany(RawMaterial::class,'category_id'); } }
