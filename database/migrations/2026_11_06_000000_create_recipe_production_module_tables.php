<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('recipe_material_categories', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('tenant_id')->nullable()->index(); $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->string('code', 40); $table->string('name'); $table->text('description')->nullable(); $table->boolean('active')->default(true); $table->timestamps(); $table->softDeletes();
            $table->unique(['tenant_id', 'code']);
        });
        Schema::create('recipe_materials', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('tenant_id')->nullable()->index(); $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->foreignId('category_id')->nullable()->constrained('recipe_material_categories')->nullOnDelete();
            $table->string('code', 40); $table->string('name'); $table->text('description')->nullable(); $table->decimal('package_size', 18, 6); $table->string('package_unit', 8); $table->decimal('package_cost', 18, 6); $table->string('base_unit', 8); $table->decimal('unit_cost', 18, 6); $table->decimal('available_quantity', 18, 6)->default(0); $table->string('barcode')->nullable(); $table->decimal('minimum_stock_alert', 18, 6)->default(0); $table->boolean('active')->default(true); $table->timestamps(); $table->softDeletes();
            $table->unique(['tenant_id', 'code']); $table->index(['tenant_id', 'name']);
        });
        Schema::create('product_recipes', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('tenant_id')->nullable()->index(); $table->unsignedBigInteger('branch_id')->nullable()->index(); $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('code', 40); $table->string('package_size')->nullable(); $table->string('package_unit', 8)->nullable(); $table->text('description')->nullable(); $table->string('status')->default('active'); $table->decimal('total_cost', 18, 6)->default(0); $table->timestamps(); $table->softDeletes();
            $table->unique(['tenant_id', 'product_id']); $table->unique(['tenant_id', 'code']);
        });
        Schema::create('product_recipe_items', function (Blueprint $table) {
            $table->id(); $table->foreignId('recipe_id')->constrained('product_recipes')->cascadeOnDelete(); $table->foreignId('material_id')->constrained('recipe_materials')->restrictOnDelete(); $table->decimal('required_quantity', 18, 6); $table->string('unit', 8); $table->decimal('waste_quantity', 18, 6)->default(0); $table->decimal('unit_cost', 18, 6); $table->decimal('total_cost', 18, 6); $table->timestamps(); $table->unique(['recipe_id', 'material_id']);
        });
        Schema::create('recipe_production_orders', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('tenant_id')->nullable()->index(); $table->unsignedBigInteger('branch_id')->nullable()->index(); $table->foreignId('recipe_id')->constrained('product_recipes')->restrictOnDelete(); $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('order_number', 50); $table->decimal('planned_quantity', 18, 6); $table->decimal('produced_quantity', 18, 6)->default(0); $table->decimal('expected_cost', 18, 6)->default(0); $table->decimal('actual_cost', 18, 6)->default(0); $table->decimal('actual_waste', 18, 6)->default(0); $table->string('status')->default('draft'); $table->timestamp('confirmed_at')->nullable(); $table->text('notes')->nullable(); $table->timestamps(); $table->softDeletes(); $table->unique(['tenant_id', 'order_number']);
        });
        Schema::create('recipe_production_order_items', function (Blueprint $table) {
            $table->id(); $table->foreignId('production_order_id')->constrained('recipe_production_orders')->cascadeOnDelete(); $table->foreignId('material_id')->constrained('recipe_materials')->restrictOnDelete(); $table->decimal('expected_quantity', 18, 6); $table->decimal('expected_waste', 18, 6); $table->decimal('actual_quantity', 18, 6)->default(0); $table->decimal('actual_waste', 18, 6)->default(0); $table->decimal('unit_cost_snapshot', 18, 6); $table->decimal('total_cost', 18, 6)->default(0); $table->string('unit', 8); $table->timestamps();
        });
        Schema::create('recipe_material_stock_movements', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('tenant_id')->nullable()->index(); $table->foreignId('material_id')->constrained('recipe_materials')->restrictOnDelete(); $table->foreignId('production_order_id')->nullable()->constrained('recipe_production_orders')->nullOnDelete(); $table->string('type', 30); $table->decimal('quantity_delta', 18, 6); $table->decimal('balance_after', 18, 6); $table->decimal('unit_cost', 18, 6)->default(0); $table->text('notes')->nullable(); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('recipe_material_stock_movements'); Schema::dropIfExists('recipe_production_order_items'); Schema::dropIfExists('recipe_production_orders'); Schema::dropIfExists('product_recipe_items'); Schema::dropIfExists('product_recipes'); Schema::dropIfExists('recipe_materials'); Schema::dropIfExists('recipe_material_categories');
    }
};
