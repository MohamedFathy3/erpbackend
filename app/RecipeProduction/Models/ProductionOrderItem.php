<?php
namespace App\RecipeProduction\Models;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class ProductionOrderItem extends BaseModel { protected $table='recipe_production_order_items'; protected $guarded=['id']; protected $casts=['expected_quantity'=>'decimal:6','expected_waste'=>'decimal:6','actual_quantity'=>'decimal:6','actual_waste'=>'decimal:6','unit_cost_snapshot'=>'decimal:6','total_cost'=>'decimal:6']; public function order(): BelongsTo { return $this->belongsTo(ProductionOrder::class,'production_order_id'); } public function material(): BelongsTo { return $this->belongsTo(RawMaterial::class,'material_id'); } }
