<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('inventory_variant_stocks')) {
            Schema::create('inventory_variant_stocks', function (Blueprint $table) {
                $table->id();
                $table->string('identity_key', 180)->unique();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_unit_id')->nullable()->constrained('product_units')->nullOnDelete();
                $table->foreignId('size_id')->nullable()->constrained('sizes')->nullOnDelete();
                $table->foreignId('color_id')->nullable()->constrained('colors')->nullOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
                $table->decimal('stock', 14, 3)->default(0);
                $table->timestamps();
                $table->index(['product_id', 'warehouse_id']);
            });
        }

        if (Schema::hasTable('product_unit_colors')) {
            DB::table('product_unit_colors as puc')
                ->join('product_units as pu', 'pu.id', '=', 'puc.product_unit_id')
                ->select('pu.product_id', 'puc.product_unit_id', 'puc.color_id', DB::raw('SUM(puc.stock) as stock'))
                ->whereNull('puc.deleted_at')
                ->groupBy('pu.product_id', 'puc.product_unit_id', 'puc.color_id')
                ->get()
                ->each(function ($row) {
                    $identity = [
                        'product_id' => (int) $row->product_id,
                        'product_unit_id' => (int) $row->product_unit_id,
                        'size_id' => null,
                        'color_id' => (int) $row->color_id,
                        'branch_id' => null,
                        'warehouse_id' => null,
                    ];
                    DB::table('inventory_variant_stocks')->updateOrInsert(
                        ['identity_key' => self::identityKey($identity)],
                        $identity + [
                            'stock' => $row->stock,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                });
        }
    }

    private static function identityKey(array $identity): string
    {
        return implode(':', array_map(static fn ($value) => $value === null ? '0' : (string) $value, [
            $identity['product_id'],
            $identity['product_unit_id'],
            $identity['size_id'],
            $identity['color_id'],
            $identity['branch_id'],
            $identity['warehouse_id'],
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_variant_stocks');
    }
};
