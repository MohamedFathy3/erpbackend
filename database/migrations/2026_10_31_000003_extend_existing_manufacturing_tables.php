<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (!Schema::hasColumn('products', 'stock')) {
                $table->decimal('stock', 18, 6)->default(0);
            } elseif (Schema::getColumnType('products', 'stock', true) !== 'decimal(18,6)') {
                $table->decimal('stock', 18, 6)->default(0)->change();
            }
        });
        Schema::table('product_warehouse', function (Blueprint $table): void {
            if (!Schema::hasColumn('product_warehouse', 'stock')) {
                $table->decimal('stock', 18, 6)->default(0);
            } elseif (Schema::getColumnType('product_warehouse', 'stock', true) !== 'decimal(18,6)') {
                $table->decimal('stock', 18, 6)->default(0)->change();
            }
        });
        Schema::table('inventory_movements', function (Blueprint $table): void {
            if (Schema::hasColumn('inventory_movements', 'quantity_delta')
                && Schema::getColumnType('inventory_movements', 'quantity_delta', true) !== 'decimal(14,6)') {
                $table->decimal('quantity_delta', 14, 6)->change();
            }
            if (Schema::hasColumn('inventory_movements', 'balance_after')
                && Schema::getColumnType('inventory_movements', 'balance_after', true) !== 'decimal(14,6)') {
                $table->decimal('balance_after', 14, 6)->nullable()->change();
            }
        });

        $backfillPlannedAt = !Schema::hasColumn('manufacturing_orders', 'planned_at')
            && Schema::hasColumn('manufacturing_orders', 'planned_start_date');
        $statusType = Schema::hasColumn('manufacturing_orders', 'status')
            ? Schema::getColumnType('manufacturing_orders', 'status')
            : null;

        Schema::table('manufacturing_orders', function (Blueprint $table) use ($statusType): void {
            if (!Schema::hasColumn('manufacturing_orders', 'bom_version_id')) {
                $table->foreignId('bom_version_id')->nullable();
            }
            if (!Schema::hasColumn('manufacturing_orders', 'branch_id')) {
                $table->foreignId('branch_id')->nullable();
            }
            if (!Schema::hasColumn('manufacturing_orders', 'source_invoice_id')) {
                $table->foreignId('source_invoice_id')->nullable();
            }
            if (!Schema::hasColumn('manufacturing_orders', 'source_invoice_item_id')) {
                $table->unsignedBigInteger('source_invoice_item_id')->nullable();
            }
            if (!Schema::hasColumn('manufacturing_orders', 'planned_cost')) {
                $table->decimal('planned_cost', 18, 6)->default(0);
            }
            if (!Schema::hasColumn('manufacturing_orders', 'actual_cost')) {
                $table->decimal('actual_cost', 18, 6)->default(0);
            }
            if (!Schema::hasColumn('manufacturing_orders', 'source_sales_order_id')) {
                $table->unsignedBigInteger('source_sales_order_id')->nullable();
            }
            if (!Schema::hasColumn('manufacturing_orders', 'scrapped_quantity')) {
                $table->decimal('scrapped_quantity', 18, 6)->default(0);
            }
            if (!Schema::hasColumn('manufacturing_orders', 'reserved_quantity')) {
                $table->decimal('reserved_quantity', 18, 6)->default(0);
            }
            if (!Schema::hasColumn('manufacturing_orders', 'planned_at')) {
                $table->dateTime('planned_at')->nullable();
            }
            if (!Schema::hasColumn('manufacturing_orders', 'started_at')) {
                $table->dateTime('started_at')->nullable();
            }
            if (!Schema::hasColumn('manufacturing_orders', 'completed_at')) {
                $table->dateTime('completed_at')->nullable();
            }
            if (!Schema::hasColumn('manufacturing_orders', 'closed_at')) {
                $table->dateTime('closed_at')->nullable();
            }
            if (!Schema::hasColumn('manufacturing_orders', 'created_by')) {
                $table->foreignId('created_by')->nullable();
            }
            if ($statusType === null) {
                $table->string('status')->default('draft');
            } elseif ($statusType !== 'string') {
                $table->string('status')->default('draft')->change();
            }
            if (!Schema::hasColumn('manufacturing_orders', 'planned_quantity')) {
                $table->decimal('planned_quantity', 18, 6)->default(0);
            } elseif (Schema::getColumnType('manufacturing_orders', 'planned_quantity', true) !== 'decimal(18,6)') {
                $table->decimal('planned_quantity', 18, 6)->change();
            }
            if (!Schema::hasColumn('manufacturing_orders', 'produced_quantity')) {
                $table->decimal('produced_quantity', 18, 6)->default(0);
            } elseif (Schema::getColumnType('manufacturing_orders', 'produced_quantity', true) !== 'decimal(18,6)') {
                $table->decimal('produced_quantity', 18, 6)->default(0)->change();
            }
        });

        if ($backfillPlannedAt) {
            DB::table('manufacturing_orders')
                ->whereNull('planned_at')
                ->whereNotNull('planned_start_date')
                ->update(['planned_at' => DB::raw('planned_start_date')]);
        }

        $backfillNotes = !Schema::hasColumn('manufacturing_cost_entries', 'notes')
            && Schema::hasColumn('manufacturing_cost_entries', 'description');
        $amountType = Schema::hasColumn('manufacturing_cost_entries', 'amount')
            ? Schema::getColumnType('manufacturing_cost_entries', 'amount', true)
            : null;

        Schema::table('manufacturing_cost_entries', function (Blueprint $table) use ($amountType): void {
            if (!Schema::hasColumn('manufacturing_cost_entries', 'manufacturing_operation_id')) {
                $table->foreignId('manufacturing_operation_id')->nullable();
            }
            if (!Schema::hasColumn('manufacturing_cost_entries', 'quantity')) {
                $table->decimal('quantity', 18, 6)->default(1);
            }
            if (!Schema::hasColumn('manufacturing_cost_entries', 'unit_cost')) {
                $table->decimal('unit_cost', 18, 6)->default(0);
            }
            if (!Schema::hasColumn('manufacturing_cost_entries', 'notes')) {
                $table->text('notes')->nullable();
            }
            if ($amountType === null) {
                $table->decimal('amount', 18, 6)->default(0);
            } elseif ($amountType !== 'decimal(18,6)') {
                $table->decimal('amount', 18, 6)->change();
            }
        });

        if ($backfillNotes) {
            DB::table('manufacturing_cost_entries')
                ->whereNull('notes')
                ->whereNotNull('description')
                ->update(['notes' => DB::raw('description')]);
        }

        $this->ensureTenantColumn('bom_versions', 'product_id', 'products');
        $this->ensureTenantColumn('bom_items', 'bom_version_id', 'bom_versions');
        $this->ensureTenantColumn('manufacturing_order_status_histories', 'manufacturing_order_id', 'manufacturing_orders');
        $this->ensureTenantColumn('manufacturing_order_materials', 'manufacturing_order_id', 'manufacturing_orders');
        $this->ensureTenantColumn('manufacturing_operations', 'manufacturing_order_id', 'manufacturing_orders');
        $this->ensureTenantColumn('manufacturing_equipment', 'branch_id', 'branches');
        $this->ensureTenantColumn('manufacturing_operation_logs', 'manufacturing_operation_id', 'manufacturing_operations');
        $this->ensureTenantColumn('quality_inspections', 'manufacturing_order_id', 'manufacturing_orders');
        $this->ensureTenantColumn('manufacturing_stock_movements', 'manufacturing_order_id', 'manufacturing_orders');
        $this->ensureTenantColumn('manufacturing_idempotency_keys', 'user_id', 'users');
        $this->replaceIdempotencyUniqueIndex();

        $this->ensureForeignKey('manufacturing_orders', 'bom_version_id', 'bom_versions', 'mo_orders_bom_version_fk', 'set null');
        $this->ensureForeignKey('manufacturing_orders', 'branch_id', 'branches', 'mo_orders_branch_fk', 'set null');
        $this->ensureForeignKey('manufacturing_orders', 'source_invoice_id', 'invoices', 'mo_orders_source_invoice_fk', 'set null');
        $this->ensureForeignKey('manufacturing_orders', 'source_invoice_item_id', 'invoice_items', 'mo_orders_invoice_item_fk', 'set null');
        $this->ensureForeignKey('manufacturing_orders', 'created_by', 'users', 'mo_orders_created_by_fk', 'set null');
        $this->ensureIndex('manufacturing_orders', ['branch_id', 'warehouse_id', 'status'], 'mo_orders_branch_wh_status_idx');
        $this->ensureForeignKey('manufacturing_cost_entries', 'manufacturing_operation_id', 'manufacturing_operations', 'mo_cost_entries_operation_fk', 'set null');

        $this->ensureForeignKey('manufacturing_order_status_histories', 'manufacturing_order_id', 'manufacturing_orders', 'mo_status_hist_order_fk', 'cascade');
        $this->ensureForeignKey('manufacturing_order_status_histories', 'created_by', 'users', 'mo_status_hist_user_fk', 'set null');
        $this->ensureUniqueIndex('manufacturing_order_status_histories', ['manufacturing_order_id', 'idempotency_key'], 'mo_status_hist_idempotency_unique');
        $this->ensureIndex('manufacturing_order_materials', ['manufacturing_order_id', 'product_id'], 'mo_order_materials_order_product_idx');
        $this->ensureUniqueIndex('manufacturing_operation_logs', ['manufacturing_operation_id', 'idempotency_key'], 'mo_operation_logs_idempotency_unique');
        $this->ensureUniqueIndex('manufacturing_stock_movements', ['manufacturing_order_id', 'movement_type', 'idempotency_key'], 'mo_stock_movements_idempotency_unique');
    }

    private function ensureForeignKey(string $table, string $column, string $referencedTable, string $name, string $onDelete): void
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (($foreignKey['columns'] ?? []) === [$column]
                && ($foreignKey['foreign_table'] ?? null) === $referencedTable) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $referencedTable, $name, $onDelete): void {
            $foreignKey = $blueprint->foreign($column, $name)->references('id')->on($referencedTable);
            if ($onDelete === 'cascade') {
                $foreignKey->cascadeOnDelete();
            }
            if ($onDelete === 'set null') {
                $foreignKey->nullOnDelete();
            }
        });
    }

    private function ensureUniqueIndex(string $table, array $columns, string $name): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['columns'] ?? []) === $columns && ($index['unique'] ?? false)) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
            $blueprint->unique($columns, $name);
        });
    }

    private function ensureIndex(string $table, array $columns, string $name): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['columns'] ?? []) === $columns) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
            $blueprint->index($columns, $name);
        });
    }

    private function replaceIdempotencyUniqueIndex(): void
    {
        $legacyIndex = null;
        foreach (Schema::getIndexes('manufacturing_idempotency_keys') as $index) {
            if (($index['columns'] ?? []) === ['key', 'action', 'user_id'] && ($index['unique'] ?? false)) {
                $legacyIndex = $index['name'];
                break;
            }
        }

        if ($legacyIndex !== null) {
            Schema::table('manufacturing_idempotency_keys', function (Blueprint $table) use ($legacyIndex): void {
                $table->dropUnique($legacyIndex);
            });
        }

        $this->ensureUniqueIndex(
            'manufacturing_idempotency_keys',
            ['key', 'action', 'user_id', 'tenant_id'],
            'mo_idempotency_tenant_unique',
        );
    }

    private function ensureTenantColumn(string $table, string $foreignColumn, string $parentTable): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        if (!Schema::hasColumn($table, 'tenant_id')) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedBigInteger('tenant_id')->nullable()->index();
            });
        }

        if (Schema::hasColumn($table, $foreignColumn)
            && Schema::hasColumn($parentTable, 'tenant_id')) {
            DB::table($table)
                ->whereNull('tenant_id')
                ->whereNotNull($foreignColumn)
                ->orderBy('id')
                ->chunkById(500, function ($records) use ($table, $foreignColumn, $parentTable): void {
                    $tenantIdsByParent = DB::table($parentTable)
                        ->whereIn('id', $records->pluck($foreignColumn)->unique())
                        ->whereNotNull('tenant_id')
                        ->pluck('tenant_id', 'id');

                    foreach ($records as $record) {
                        $tenantId = $tenantIdsByParent[$record->{$foreignColumn}] ?? null;
                        if ($tenantId) {
                            DB::table($table)->where('id', $record->id)->update(['tenant_id' => $tenantId]);
                        }
                    }
                });
        }

        $defaultTenantId = DB::table('tenants')->where('slug', 'default')->value('id');
        if ($defaultTenantId) {
            DB::table($table)->whereNull('tenant_id')->update(['tenant_id' => $defaultTenantId]);
        }
    }

    public function down(): void
    {
        // Keep this compatibility migration additive to avoid removing data from legacy tables.
    }
};
