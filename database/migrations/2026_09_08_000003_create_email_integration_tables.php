<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('email_templates')) Schema::create('email_templates', function (Blueprint $table) { $table->id(); $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete(); $table->string('name'); $table->string('slug')->nullable(); $table->string('subject'); $table->longText('body'); $table->text('bcc')->nullable(); $table->boolean('is_active')->default(true); $table->timestamps(); $table->index(['tenant_id','slug']); });
        elseif (!Schema::hasColumn('email_templates', 'tenant_id')) Schema::table('email_templates', fn(Blueprint $table) => $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete());
        Schema::create('email_logs', function (Blueprint $table) { $table->id(); $table->foreignId('tenant_id')->constrained()->cascadeOnDelete(); $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete(); $table->foreignId('template_id')->nullable()->constrained('email_templates')->nullOnDelete(); $table->string('to_email'); $table->string('subject'); $table->enum('status',['queued','sent','failed'])->default('queued'); $table->text('error_message')->nullable(); $table->timestamp('sent_at')->nullable(); $table->json('meta')->nullable(); $table->timestamps(); $table->index(['tenant_id','status','created_at']); });
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            foreach ([['welcome','Welcome','Welcome to {{customer_name}}','<p>Hello {{customer_name}},</p><p>Welcome to our company.</p>'],['follow-up','Follow-up','Following up with {{customer_name}}','<p>Hello {{customer_name}},</p><p>Following up on our conversation.</p>']] as $default) {
                if (!DB::table('email_templates')->where('tenant_id',$tenantId)->where('slug',$default[0])->exists()) DB::table('email_templates')->insert(['tenant_id'=>$tenantId,'name'=>$default[1],'slug'=>$default[0],'subject'=>$default[2],'body'=>$default[3],'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
            }
        }
    }
    public function down(): void { Schema::dropIfExists('email_logs'); if (Schema::hasTable('email_templates')) Schema::dropIfExists('email_templates'); }
};
