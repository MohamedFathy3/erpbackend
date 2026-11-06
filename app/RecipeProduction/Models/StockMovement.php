<?php
namespace App\RecipeProduction\Models;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class StockMovement extends BaseModel { protected $table='recipe_material_stock_movements'; protected $guarded=['id']; protected $casts=['quantity_delta'=>'decimal:6','balance_after'=>'decimal:6','unit_cost'=>'decimal:6']; public function material(): BelongsTo { return $this->belongsTo(RawMaterial::class,'material_id'); } public function order(): BelongsTo { return $this->belongsTo(ProductionOrder::class,'production_order_id'); } }
