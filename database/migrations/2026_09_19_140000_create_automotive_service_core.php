<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('automotive_vehicles')) Schema::create('automotive_vehicles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('plate_number')->nullable();
            $table->string('vin')->nullable();
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('model_year')->nullable();
            $table->string('color')->nullable();
            $table->string('fuel_type')->nullable();
            $table->string('external_catalog_id')->nullable();
            $table->decimal('current_mileage', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'plate_number']);
            $table->index(['tenant_id', 'vin']);
        });

        if (!Schema::hasTable('automotive_services')) Schema::create('automotive_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->text('description')->nullable();
            $table->decimal('selling_price', 14, 2)->default(0);
            $table->decimal('estimated_cost', 14, 2)->default(0);
            $table->unsignedInteger('estimated_minutes')->nullable();
            $table->boolean('warranty_eligible')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'code']);
        });

        if (!Schema::hasTable('automotive_service_orders')) Schema::create('automotive_service_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('order_number');
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('automotive_vehicles')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('advisor_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status')->default('draft');
            $table->string('priority')->default('normal');
            $table->decimal('odometer', 12, 2)->nullable();
            $table->text('customer_request')->nullable();
            $table->text('internal_notes')->nullable();
            $table->dateTime('promised_at')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('payment_status')->default('unpaid');
            $table->unsignedBigInteger('sales_invoice_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'order_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'vehicle_id']);
        });

        if (!Schema::hasTable('automotive_service_order_items')) Schema::create('automotive_service_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_order_id')->constrained('automotive_service_orders')->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('automotive_services')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->boolean('requires_approval')->default(false);
            $table->boolean('approved')->default(true);
            $table->timestamps();
        });

        if (!Schema::hasTable('automotive_service_order_technicians')) Schema::create('automotive_service_order_technicians', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_order_id')->constrained('automotive_service_orders')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['service_order_id', 'employee_id'], 'auto_order_tech_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automotive_service_order_technicians');
        Schema::dropIfExists('automotive_service_order_items');
        Schema::dropIfExists('automotive_service_orders');
        Schema::dropIfExists('automotive_services');
        Schema::dropIfExists('automotive_vehicles');
    }
};
