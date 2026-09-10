<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private array $tables = ['admins','users','banks','branches','categories','cities','colors','currencies','customers','delevery_men','employees','attendances','finances','inventory_logs','inventory_movements','invoice_items','invoice_payments','invoices','journal_entries','journal_entry_lines','loyalty_settings','manufacturing_boms','manufacturing_bom_items','manufacturing_cost_entries','manufacturing_orders','manufacturing_order_operations','manufacturing_quality_inspections','manufacturing_work_centers','offers','products','product_units','product_unit_colors','projects','project_claims','project_claim_items','project_cost_entries','project_wbs_items','purchase_invoice_items','purchase_invoices','purchase_order_items','purchase_orders','purchase_return_items','purchase_returns','return_invoices','return_items','revenues','sales_invoice_items','sales_invoice_return_items','sales_invoice_returns','sales_invoices','sales_representatives','suppliers','taxes','treasuries','treasury_transactions','units','warehouses','workflow_transactions'];
    public function up(): void {
        if (!Schema::hasTable('tenants')) {
            Schema::create('tenants', function (Blueprint $table) { $table->id(); $table->string('name'); $table->string('slug')->unique(); $table->string('domain')->nullable(); $table->enum('status',['active','suspended','trial'])->default('trial'); $table->string('plan')->default('starter'); $table->timestamps(); });
        }
        if (!Schema::hasTable('tenant_modules')) {
            Schema::create('tenant_modules', function (Blueprint $table) { $table->id(); $table->foreignId('tenant_id')->constrained()->cascadeOnDelete(); $table->string('module_key'); $table->boolean('is_enabled')->default(true); $table->timestamps(); $table->unique(['tenant_id','module_key']); });
        }
        $tenantId = DB::table('tenants')->where('slug', 'default')->value('id');
        if (!$tenantId) {
            $tenantId = DB::table('tenants')->insertGetId(['name'=>'Default Tenant','slug'=>'default','status'=>'active','plan'=>'legacy','created_at'=>now(),'updated_at'=>now()]);
        }
        foreach ($this->tables as $name) if (Schema::hasTable($name) && !Schema::hasColumn($name,'tenant_id')) { Schema::table($name, function (Blueprint $table) { $table->foreignId('tenant_id')->nullable()->index()->constrained('tenants')->nullOnDelete(); }); DB::table($name)->whereNull('tenant_id')->update(['tenant_id'=>$tenantId]); }
        foreach (['dashboard','pos','inventory','purchasing','sales','finance','hr','crm','reports','settings','industries','manufacturing','projects','workflow','email','whatsapp','google_calendar','google_drive','tasks','manufacturing_setup','product_ledger'] as $key) DB::table('tenant_modules')->insertOrIgnore(['tenant_id'=>$tenantId,'module_key'=>$key,'is_enabled'=>true,'created_at'=>now(),'updated_at'=>now()]);
    }
    public function down(): void { foreach (array_reverse($this->tables) as $name) if (Schema::hasTable($name) && Schema::hasColumn($name,'tenant_id')) Schema::table($name, fn(Blueprint $table) => $table->dropConstrainedForeignId('tenant_id')); Schema::dropIfExists('tenant_modules'); Schema::dropIfExists('tenants'); }
};
