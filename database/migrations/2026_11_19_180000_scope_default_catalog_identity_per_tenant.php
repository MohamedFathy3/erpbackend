<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'currencies' => ['code', 'currencies_tenant_code_unique'],
            'taxes' => ['name', 'taxes_tenant_name_unique'],
            'categories' => ['name', 'categories_tenant_name_unique'],
            'units' => ['code', 'units_tenant_code_unique'],
            'products' => ['sku', 'products_tenant_sku_unique'],
            'treasuries' => ['code', 'treasuries_tenant_code_unique'],
        ];

        foreach ($tables as $tableName => [$column, $indexName]) {
            if (!Schema::hasTable($tableName)
                || !Schema::hasColumn($tableName, 'tenant_id')
                || !Schema::hasColumn($tableName, $column)) {
                continue;
            }

            foreach (Schema::getIndexes($tableName) as $index) {
                $columns = $index['columns'] ?? [];
                $isUnique = (bool) ($index['unique'] ?? false);
                if ($isUnique && $columns === [$column]) {
                    Schema::table($tableName, function (Blueprint $table) use ($index): void {
                        $table->dropUnique($index['name']);
                    });
                }
            }

            $hasTenantIndex = collect(Schema::getIndexes($tableName))->contains(
                fn (array $index): bool => (bool) ($index['unique'] ?? false)
                    && ($index['columns'] ?? []) === ['tenant_id', $column]
            );

            if (!$hasTenantIndex) {
                Schema::table($tableName, function (Blueprint $table) use ($column, $indexName): void {
                    $table->unique(['tenant_id', $column], $indexName);
                });
            }
        }

        if (Schema::hasTable('units') && Schema::hasColumn('units', 'name')) {
            foreach (Schema::getIndexes('units') as $index) {
                if (($index['unique'] ?? false) && ($index['columns'] ?? []) === ['name']) {
                    Schema::table('units', function (Blueprint $table) use ($index): void {
                        $table->dropUnique($index['name']);
                    });
                }
            }
            if (!collect(Schema::getIndexes('units'))->contains(fn (array $index): bool => (bool) ($index['unique'] ?? false) && ($index['columns'] ?? []) === ['tenant_id', 'name'])) {
                Schema::table('units', function (Blueprint $table): void {
                    $table->unique(['tenant_id', 'name'], 'units_tenant_name_unique');
                });
            }
        }

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'barcode')) {
            foreach (Schema::getIndexes('products') as $index) {
                if (($index['unique'] ?? false) && ($index['columns'] ?? []) === ['barcode']) {
                    Schema::table('products', function (Blueprint $table) use ($index): void {
                        $table->dropUnique($index['name']);
                    });
                }
            }
            if (!collect(Schema::getIndexes('products'))->contains(fn (array $index): bool => (bool) ($index['unique'] ?? false) && ($index['columns'] ?? []) === ['tenant_id', 'barcode'])) {
                Schema::table('products', function (Blueprint $table): void {
                    $table->unique(['tenant_id', 'barcode'], 'products_tenant_barcode_unique');
                });
            }
        }
    }

    public function down(): void
    {
        $indexes = [
            'currencies' => ['currencies_tenant_code_unique', 'code', 'currencies_code_unique'],
            'taxes' => ['taxes_tenant_name_unique', 'name', 'taxes_name_unique'],
            'categories' => ['categories_tenant_name_unique', 'name', 'categories_name_unique'],
            'units' => ['units_tenant_code_unique', 'code', 'units_code_unique'],
            'products' => ['products_tenant_sku_unique', 'sku', 'products_sku_unique'],
            'treasuries' => ['treasuries_tenant_code_unique', 'code', 'treasuries_code_unique'],
        ];

        foreach ($indexes as $tableName => [$tenantIndex, $column, $globalIndex]) {
            if (!Schema::hasTable($tableName)) continue;
            if (collect(Schema::getIndexes($tableName))->contains(fn (array $index): bool => ($index['name'] ?? '') === $tenantIndex)) {
                Schema::table($tableName, function (Blueprint $table) use ($tenantIndex): void {
                    $table->dropUnique($tenantIndex);
                });
            }
            if (Schema::hasColumn($tableName, $column)) {
                Schema::table($tableName, function (Blueprint $table) use ($column, $globalIndex): void {
                    $table->unique($column, $globalIndex);
                });
            }
        }

        if (Schema::hasTable('units') && collect(Schema::getIndexes('units'))->contains(fn (array $index): bool => ($index['name'] ?? '') === 'units_tenant_name_unique')) {
            Schema::table('units', function (Blueprint $table): void {
                $table->dropUnique('units_tenant_name_unique');
                $table->unique('name', 'units_name_unique');
            });
        }

        if (Schema::hasTable('products') && collect(Schema::getIndexes('products'))->contains(fn (array $index): bool => ($index['name'] ?? '') === 'products_tenant_barcode_unique')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropUnique('products_tenant_barcode_unique');
                $table->unique('barcode', 'products_barcode_unique');
            });
        }
    }
};
