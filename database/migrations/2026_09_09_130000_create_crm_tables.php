<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_stages', function (Blueprint $table): void {
            $table->id(); $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name'); $table->string('name_ar')->nullable(); $table->unsignedInteger('position')->default(0); $table->boolean('is_won')->default(false); $table->boolean('is_lost')->default(false); $table->timestamps(); $table->softDeletes();
            $table->unique(['tenant_id', 'name']);
        });
        Schema::create('leads', function (Blueprint $table): void {
            $table->id(); $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name'); $table->string('company')->nullable(); $table->string('email')->nullable(); $table->string('phone')->nullable(); $table->string('source')->nullable(); $table->string('status')->default('new'); $table->foreignId('assigned_to')->nullable()->constrained('admins')->nullOnDelete(); $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete(); $table->timestamps(); $table->softDeletes();
            $table->index(['tenant_id', 'status']);
        });
        Schema::create('deals', function (Blueprint $table): void {
            $table->id(); $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title'); $table->decimal('value', 18, 2)->default(0); $table->foreignId('pipeline_stage_id')->constrained()->restrictOnDelete(); $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete(); $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete(); $table->foreignId('assigned_to')->nullable()->constrained('admins')->nullOnDelete(); $table->date('expected_close_date')->nullable(); $table->text('notes')->nullable(); $table->timestamps(); $table->softDeletes();
            $table->index(['tenant_id', 'pipeline_stage_id']);
        });
        Schema::create('crm_activities', function (Blueprint $table): void {
            $table->id(); $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type'); $table->text('body')->nullable(); $table->timestamp('occurred_at')->nullable(); $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete(); $table->foreignId('deal_id')->nullable()->constrained()->nullOnDelete(); $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete(); $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete(); $table->timestamps(); $table->softDeletes();
            $table->index(['tenant_id', 'occurred_at']);
        });
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            foreach ([['New', 'جديد'], ['Contacted', 'تم التواصل'], ['Proposal', 'عرض سعر'], ['Negotiation', 'تفاوض'], ['Won', 'مغلقة - فوز'], ['Lost', 'مغلقة - خسارة']] as $position => [$name, $nameAr]) {
                DB::table('pipeline_stages')->insert(['tenant_id'=>$tenantId, 'name'=>$name, 'name_ar'=>$nameAr, 'position'=>$position, 'is_won'=>$name === 'Won', 'is_lost'=>$name === 'Lost', 'created_at'=>now(), 'updated_at'=>now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_activities'); Schema::dropIfExists('deals'); Schema::dropIfExists('leads'); Schema::dropIfExists('pipeline_stages');
    }
};
