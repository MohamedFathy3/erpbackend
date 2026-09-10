<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('email_templates', function (Blueprint $table): void { $table->id(); $table->foreignId('tenant_id')->constrained()->cascadeOnDelete(); $table->string('name'); $table->string('subject'); $table->longText('body'); $table->timestamps(); $table->softDeletes(); $table->unique(['tenant_id','name']); });
        Schema::create('email_logs', function (Blueprint $table): void { $table->id(); $table->foreignId('tenant_id')->constrained()->cascadeOnDelete(); $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete(); $table->foreignId('template_id')->nullable()->constrained('email_templates')->nullOnDelete(); $table->string('to_email'); $table->string('subject'); $table->text('body')->nullable(); $table->string('status')->default('queued'); $table->text('error')->nullable(); $table->timestamp('sent_at')->nullable(); $table->timestamps(); $table->index(['tenant_id','status']); });
    }
    public function down(): void { Schema::dropIfExists('email_logs'); Schema::dropIfExists('email_templates'); }
};
