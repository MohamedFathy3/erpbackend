<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automotive_customer_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('password');
            $table->boolean('active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'email']);
            $table->unique(['tenant_id', 'customer_id']);
        });

        Schema::create('automotive_warranties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('automotive_vehicles')->cascadeOnDelete();
            $table->foreignId('service_order_id')->nullable()->constrained('automotive_service_orders')->nullOnDelete();
            $table->string('policy_name');
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->decimal('mileage_limit', 12, 2)->nullable();
            $table->string('status')->default('active');
            $table->text('terms')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('automotive_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('automotive_vehicles')->cascadeOnDelete();
            $table->foreignId('service_order_id')->nullable()->constrained('automotive_service_orders')->nullOnDelete();
            $table->foreignId('advisor_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('type')->default('walk_in');
            $table->string('status')->default('scheduled');
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('checked_out_at')->nullable();
            $table->text('purpose')->nullable();
            $table->text('outcome')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('automotive_service_orders', function (Blueprint $table): void {
            $table->foreignId('warehouse_id')->nullable()->after('branch_id')->constrained()->nullOnDelete();
            $table->timestamp('inventory_consumed_at')->nullable()->after('payment_status');
        });

        Schema::table('mediable', function (Blueprint $table): void {
            if (!Schema::hasColumn('mediable', 'customer_visible')) $table->boolean('customer_visible')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('mediable', function (Blueprint $table): void {
            if (Schema::hasColumn('mediable', 'customer_visible')) $table->dropColumn('customer_visible');
        });
        Schema::table('automotive_service_orders', function (Blueprint $table): void {
            $table->dropForeign(['warehouse_id']);
            $table->dropColumn(['warehouse_id', 'inventory_consumed_at']);
        });
        Schema::dropIfExists('automotive_visits');
        Schema::dropIfExists('automotive_warranties');
        Schema::dropIfExists('automotive_customer_accounts');
    }
};
