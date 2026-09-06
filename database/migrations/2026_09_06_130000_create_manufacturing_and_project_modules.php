<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manufacturing_boms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedInteger('version')->default(1);
            $table->enum('status', ['draft', 'active', 'archived'])->default('draft');
            $table->decimal('planned_unit_cost', 14, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('manufacturing_bom_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_id')->constrained('manufacturing_boms')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->decimal('scrap_percent', 8, 3)->default(0);
            $table->string('unit')->nullable();
            $table->timestamps();
        });

        Schema::create('manufacturing_work_centers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->decimal('hourly_rate', 14, 4)->default(0);
            $table->decimal('capacity_hours_per_day', 10, 2)->default(8);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('manufacturing_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('bom_id')->nullable()->constrained('manufacturing_boms')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->decimal('planned_quantity', 14, 4);
            $table->decimal('produced_quantity', 14, 4)->default(0);
            $table->date('planned_start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            $table->enum('status', ['draft', 'planned', 'in_progress', 'quality_hold', 'completed', 'cancelled'])->default('draft');
            $table->decimal('planned_cost', 14, 2)->default(0);
            $table->decimal('actual_cost', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('manufacturing_quality_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturing_order_id')->constrained()->cascadeOnDelete();
            $table->string('inspection_number')->unique();
            $table->enum('result', ['pending', 'passed', 'failed', 'conditional'])->default('pending');
            $table->decimal('accepted_quantity', 14, 4)->default(0);
            $table->decimal('rejected_quantity', 14, 4)->default(0);
            $table->text('findings')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('project_code')->unique();
            $table->string('name');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('contract_number')->nullable();
            $table->date('start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            $table->date('actual_end_date')->nullable();
            $table->enum('status', ['draft', 'active', 'on_hold', 'completed', 'cancelled'])->default('draft');
            $table->decimal('contract_value', 14, 2)->default(0);
            $table->decimal('budget_cost', 14, 2)->default(0);
            $table->decimal('actual_cost', 14, 2)->default(0);
            $table->decimal('retention_percent', 8, 3)->default(0);
            $table->text('scope')->nullable();
            $table->timestamps();
        });

        Schema::create('project_wbs_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('project_wbs_items')->nullOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('unit')->nullable();
            $table->decimal('planned_quantity', 14, 4)->default(0);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('planned_cost', 14, 2)->default(0);
            $table->decimal('completed_quantity', 14, 4)->default(0);
            $table->decimal('completion_percent', 8, 3)->default(0);
            $table->timestamps();
            $table->unique(['project_id', 'code']);
        });

        Schema::create('project_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('claim_number')->unique();
            $table->date('claim_date');
            $table->decimal('gross_amount', 14, 2)->default(0);
            $table->decimal('advance_deduction', 14, 2)->default(0);
            $table->decimal('retention_amount', 14, 2)->default(0);
            $table->decimal('net_amount', 14, 2)->default(0);
            $table->enum('status', ['draft', 'submitted', 'approved', 'partially_paid', 'paid', 'rejected'])->default('draft');
            $table->date('approved_at')->nullable();
            $table->date('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_claims');
        Schema::dropIfExists('project_wbs_items');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('manufacturing_quality_inspections');
        Schema::dropIfExists('manufacturing_orders');
        Schema::dropIfExists('manufacturing_work_centers');
        Schema::dropIfExists('manufacturing_bom_items');
        Schema::dropIfExists('manufacturing_boms');
    }
};
