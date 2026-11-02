<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  if (!Schema::hasTable('bom_versions')) Schema::create('bom_versions', function(Blueprint $t){$t->id();$t->foreignId('product_id')->constrained('products')->restrictOnDelete();$t->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();$t->string('version');$t->string('status')->default('draft');$t->decimal('yield_quantity',18,6)->default(1);$t->date('effective_from')->nullable();$t->date('effective_to')->nullable();$t->text('notes')->nullable();$t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();$t->softDeletes();$t->timestamps();$t->unique(['product_id','version','branch_id']);$t->index(['product_id','status']);});
  if (!Schema::hasTable('bom_items')) Schema::create('bom_items', function(Blueprint $t){$t->id();$t->foreignId('bom_version_id')->constrained('bom_versions')->cascadeOnDelete();$t->foreignId('parent_item_id')->nullable()->constrained('bom_items')->cascadeOnDelete();$t->foreignId('component_product_id')->constrained('products')->restrictOnDelete();$t->foreignId('child_bom_version_id')->nullable()->constrained('bom_versions')->nullOnDelete();$t->decimal('quantity',18,6);$t->decimal('scrap_percent',8,4)->default(0);$t->string('unit')->nullable();$t->boolean('is_optional')->default(false);$t->text('notes')->nullable();$t->softDeletes();$t->timestamps();$t->index(['bom_version_id','component_product_id']);});
  if (!Schema::hasTable('manufacturing_orders')) Schema::create('manufacturing_orders', function(Blueprint $t){$t->id();$t->string('order_number')->unique();$t->foreignId('product_id')->constrained('products')->restrictOnDelete();$t->foreignId('bom_version_id')->nullable()->constrained('bom_versions')->nullOnDelete();$t->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();$t->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();$t->foreignId('source_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();$t->unsignedBigInteger('source_sales_order_id')->nullable();$t->decimal('planned_quantity',18,6);$t->decimal('produced_quantity',18,6)->default(0);$t->decimal('scrapped_quantity',18,6)->default(0);$t->decimal('reserved_quantity',18,6)->default(0);$t->string('status')->default('draft')->index();$t->dateTime('planned_at')->nullable();$t->dateTime('started_at')->nullable();$t->dateTime('completed_at')->nullable();$t->dateTime('closed_at')->nullable();$t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();$t->text('notes')->nullable();$t->softDeletes();$t->timestamps();$t->index(['branch_id','warehouse_id','status']);});
  if (!Schema::hasTable('manufacturing_order_status_histories')) Schema::create('manufacturing_order_status_histories', function(Blueprint $t){$t->id();$t->foreignId('manufacturing_order_id')->constrained('manufacturing_orders','id','mo_status_hist_order_fk')->cascadeOnDelete();$t->string('from_status')->nullable();$t->string('to_status');$t->text('reason')->nullable();$t->foreignId('created_by')->nullable()->constrained('users','id','mo_status_hist_user_fk')->nullOnDelete();$t->string('idempotency_key')->nullable();$t->softDeletes();$t->timestamps();$t->unique(['manufacturing_order_id','idempotency_key'],'mo_status_hist_idempotency_unique');});
  if (!Schema::hasTable('manufacturing_order_materials')) Schema::create('manufacturing_order_materials', function(Blueprint $t){$t->id();$t->foreignId('manufacturing_order_id')->constrained()->cascadeOnDelete();$t->foreignId('bom_item_id')->nullable()->constrained('bom_items')->nullOnDelete();$t->foreignId('product_id')->constrained('products')->restrictOnDelete();$t->decimal('required_quantity',18,6);$t->decimal('reserved_quantity',18,6)->default(0);$t->decimal('issued_quantity',18,6)->default(0);$t->decimal('returned_quantity',18,6)->default(0);$t->decimal('scrapped_quantity',18,6)->default(0);$t->decimal('unit_cost',18,6)->default(0);$t->string('status')->default('open');$t->softDeletes();$t->timestamps();$t->index(['manufacturing_order_id','product_id'],'mo_order_materials_order_product_idx');});
  if (!Schema::hasTable('manufacturing_operations')) Schema::create('manufacturing_operations', function(Blueprint $t){$t->id();$t->foreignId('manufacturing_order_id')->constrained()->cascadeOnDelete();$t->unsignedBigInteger('equipment_id')->nullable();$t->unsignedInteger('sequence');$t->string('name');$t->string('status')->default('pending');$t->decimal('planned_minutes',18,6)->default(0);$t->decimal('actual_minutes',18,6)->default(0);$t->softDeletes();$t->timestamps();$t->unique(['manufacturing_order_id','sequence']);});
  if (!Schema::hasTable('manufacturing_equipment')) Schema::create('manufacturing_equipment', function(Blueprint $t){$t->id();$t->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();$t->string('code')->unique();$t->string('name');$t->string('status')->default('available');$t->decimal('hourly_rate',18,6)->default(0);$t->text('notes')->nullable();$t->softDeletes();$t->timestamps();});
  if (!Schema::hasTable('manufacturing_operation_logs')) Schema::create('manufacturing_operation_logs', function(Blueprint $t){$t->id();$t->foreignId('manufacturing_operation_id')->constrained()->cascadeOnDelete();$t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();$t->string('action');$t->decimal('minutes',18,6)->default(0);$t->decimal('labor_cost',18,6)->default(0);$t->decimal('machine_cost',18,6)->default(0);$t->text('notes')->nullable();$t->string('idempotency_key')->nullable();$t->softDeletes();$t->timestamps();$t->unique(['manufacturing_operation_id','idempotency_key'],'mo_operation_logs_idempotency_unique');});
  if (!Schema::hasTable('quality_inspections')) Schema::create('quality_inspections', function(Blueprint $t){$t->id();$t->foreignId('manufacturing_order_id')->constrained()->cascadeOnDelete();$t->foreignId('manufacturing_operation_id')->nullable()->constrained()->nullOnDelete();$t->decimal('quantity',18,6);$t->string('result');$t->text('reason')->nullable();$t->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();$t->softDeletes();$t->timestamps();});
  if (!Schema::hasTable('manufacturing_stock_movements')) Schema::create('manufacturing_stock_movements', function(Blueprint $t){$t->id();$t->foreignId('manufacturing_order_id')->constrained()->cascadeOnDelete();$t->foreignId('product_id')->constrained('products')->restrictOnDelete();$t->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();$t->string('movement_type');$t->decimal('quantity',18,6);$t->decimal('unit_cost',18,6)->default(0);$t->string('idempotency_key')->nullable();$t->text('notes')->nullable();$t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();$t->softDeletes();$t->timestamps();$t->unique(['manufacturing_order_id','movement_type','idempotency_key'],'mo_stock_movements_idempotency_unique');$t->index(['warehouse_id','product_id']);});
  if (!Schema::hasTable('manufacturing_cost_entries')) Schema::create('manufacturing_cost_entries', function(Blueprint $t){$t->id();$t->foreignId('manufacturing_order_id')->constrained()->cascadeOnDelete();$t->foreignId('manufacturing_operation_id')->nullable()->constrained()->nullOnDelete();$t->string('cost_type');$t->decimal('quantity',18,6)->default(1);$t->decimal('unit_cost',18,6)->default(0);$t->decimal('amount',18,6);$t->text('notes')->nullable();$t->softDeletes();$t->timestamps();});
  if (!Schema::hasTable('manufacturing_idempotency_keys')) Schema::create('manufacturing_idempotency_keys', function(Blueprint $t){$t->id();$t->string('key');$t->string('action');$t->unsignedBigInteger('user_id')->nullable();$t->json('response')->nullable();$t->softDeletes();$t->timestamps();$t->unique(['key','action','user_id']);});

  $this->extendLegacyManufacturingTables();

  $this->ensureForeignKey('manufacturing_orders', 'bom_version_id', 'bom_versions', 'mo_orders_bom_version_fk', 'set null');
  $this->ensureForeignKey('manufacturing_orders', 'branch_id', 'branches', 'mo_orders_branch_fk', 'set null');
  $this->ensureForeignKey('manufacturing_orders', 'source_invoice_id', 'invoices', 'mo_orders_source_invoice_fk', 'set null');
  $this->ensureForeignKey('manufacturing_orders', 'created_by', 'users', 'mo_orders_created_by_fk', 'set null');
  $this->ensureIndex('manufacturing_orders', ['branch_id', 'warehouse_id', 'status'], 'mo_orders_branch_wh_status_idx');
  $this->ensureForeignKey('manufacturing_cost_entries', 'manufacturing_operation_id', 'manufacturing_operations', 'mo_cost_entries_operation_fk', 'set null');
  $this->ensureForeignKey('manufacturing_order_status_histories', 'manufacturing_order_id', 'manufacturing_orders', 'mo_status_hist_order_fk', 'cascade');
  $this->ensureForeignKey('manufacturing_order_status_histories', 'created_by', 'users', 'mo_status_hist_user_fk', 'set null');
  $this->ensureUniqueIndex('manufacturing_order_status_histories', ['manufacturing_order_id', 'idempotency_key'], 'mo_status_hist_idempotency_unique');
 }

 private function extendLegacyManufacturingTables(): void {
  if (Schema::hasTable('manufacturing_orders')) {
   $backfillPlannedAt = !Schema::hasColumn('manufacturing_orders', 'planned_at')
    && Schema::hasColumn('manufacturing_orders', 'planned_start_date');
   $statusType = Schema::getColumnType('manufacturing_orders', 'status');

   Schema::table('manufacturing_orders', function(Blueprint $table) use ($statusType) {
    if (!Schema::hasColumn('manufacturing_orders', 'bom_version_id')) $table->foreignId('bom_version_id')->nullable();
    if (!Schema::hasColumn('manufacturing_orders', 'branch_id')) $table->foreignId('branch_id')->nullable();
    if (!Schema::hasColumn('manufacturing_orders', 'source_invoice_id')) $table->foreignId('source_invoice_id')->nullable();
    if (!Schema::hasColumn('manufacturing_orders', 'source_sales_order_id')) $table->unsignedBigInteger('source_sales_order_id')->nullable();
    if (!Schema::hasColumn('manufacturing_orders', 'scrapped_quantity')) $table->decimal('scrapped_quantity', 18, 6)->default(0);
    if (!Schema::hasColumn('manufacturing_orders', 'reserved_quantity')) $table->decimal('reserved_quantity', 18, 6)->default(0);
    if (!Schema::hasColumn('manufacturing_orders', 'planned_at')) $table->dateTime('planned_at')->nullable();
    if (!Schema::hasColumn('manufacturing_orders', 'started_at')) $table->dateTime('started_at')->nullable();
    if (!Schema::hasColumn('manufacturing_orders', 'completed_at')) $table->dateTime('completed_at')->nullable();
    if (!Schema::hasColumn('manufacturing_orders', 'closed_at')) $table->dateTime('closed_at')->nullable();
    if (!Schema::hasColumn('manufacturing_orders', 'created_by')) $table->foreignId('created_by')->nullable();
    if ($statusType !== 'string') $table->string('status')->default('draft')->change();
   });

   if ($backfillPlannedAt) {
    \Illuminate\Support\Facades\DB::table('manufacturing_orders')
     ->whereNull('planned_at')
     ->whereNotNull('planned_start_date')
     ->update(['planned_at' => \Illuminate\Support\Facades\DB::raw('planned_start_date')]);
   }
  }

  if (Schema::hasTable('manufacturing_cost_entries')) {
   $backfillNotes = !Schema::hasColumn('manufacturing_cost_entries', 'notes')
    && Schema::hasColumn('manufacturing_cost_entries', 'description');
   $amountType = Schema::getColumnType('manufacturing_cost_entries', 'amount', true);

   Schema::table('manufacturing_cost_entries', function(Blueprint $table) use ($amountType) {
    if (!Schema::hasColumn('manufacturing_cost_entries', 'manufacturing_operation_id')) $table->foreignId('manufacturing_operation_id')->nullable();
    if (!Schema::hasColumn('manufacturing_cost_entries', 'quantity')) $table->decimal('quantity', 18, 6)->default(1);
    if (!Schema::hasColumn('manufacturing_cost_entries', 'unit_cost')) $table->decimal('unit_cost', 18, 6)->default(0);
    if (!Schema::hasColumn('manufacturing_cost_entries', 'notes')) $table->text('notes')->nullable();
    if ($amountType !== 'decimal(18,6)') $table->decimal('amount', 18, 6)->change();
   });

   if ($backfillNotes) {
    \Illuminate\Support\Facades\DB::table('manufacturing_cost_entries')
     ->whereNull('notes')
     ->whereNotNull('description')
     ->update(['notes' => \Illuminate\Support\Facades\DB::raw('description')]);
   }
  }
 }

 private function ensureForeignKey(string $table, string $column, string $referencedTable, string $name, string $onDelete): void {
  foreach (Schema::getForeignKeys($table) as $foreignKey) {
   if (($foreignKey['columns'] ?? []) === [$column] && ($foreignKey['foreign_table'] ?? null) === $referencedTable) return;
  }

  Schema::table($table, function(Blueprint $blueprint) use ($column, $referencedTable, $name, $onDelete) {
   $foreignKey = $blueprint->foreign($column, $name)->references('id')->on($referencedTable);
   if ($onDelete === 'cascade') $foreignKey->cascadeOnDelete();
   if ($onDelete === 'set null') $foreignKey->nullOnDelete();
  });
 }

 private function ensureUniqueIndex(string $table, array $columns, string $name): void {
  foreach (Schema::getIndexes($table) as $index) {
   if (($index['columns'] ?? []) === $columns && ($index['unique'] ?? false)) return;
  }

  Schema::table($table, function(Blueprint $blueprint) use ($columns, $name) {
   $blueprint->unique($columns, $name);
  });
 }

 private function ensureIndex(string $table, array $columns, string $name): void {
  foreach (Schema::getIndexes($table) as $index) {
   if (($index['columns'] ?? []) === $columns) return;
  }

  Schema::table($table, function(Blueprint $blueprint) use ($columns, $name) {
   $blueprint->index($columns, $name);
  });
 }

 public function down(): void { foreach(['manufacturing_idempotency_keys','manufacturing_stock_movements','quality_inspections','manufacturing_operation_logs','manufacturing_equipment','manufacturing_operations','manufacturing_order_materials','manufacturing_order_status_histories','bom_items','bom_versions'] as $t) Schema::dropIfExists($t); }
};
