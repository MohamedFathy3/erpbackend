<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillProductWarehouses extends Command
{
    protected $signature = 'inventory:backfill-warehouses
                            {--tenant= : رقم tenant واحد (بدونه كل الـ tenants)}
                            {--apply : نفّذ فعلياً (بدونه معاينة فقط)}';

    protected $description = 'ربط المنتجات غير المربوطة بأي مخزن بالمخزن الرئيسي لكل tenant';

    public function handle(): int
    {
        $tenantIds = $this->option('tenant')
            ? [(int) $this->option('tenant')]
            : DB::table('tenants')->pluck('id')->all();

        $apply = (bool) $this->option('apply');
        $now = now();
        $totalLinked = 0;

        foreach ($tenantIds as $tenantId) {
            // ✅ المخزن الرئيسي: main_branch أولاً، وإلا أصغر id
            $warehouse = DB::table('warehouses')
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->orderByDesc('main_branch')
                ->orderBy('id')
                ->first(['id', 'name', 'branch_id']);

            if (!$warehouse) {
                $this->line("tenant {$tenantId}: مفيش مخازن، تخطي");
                continue;
            }

            // ✅ المنتجات اللي مش مربوطة بأي مخزن (نتحقق بـ tenant_id)
            $unlinked = DB::table('products')
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('product_warehouse')
                        ->whereColumn('product_warehouse.product_id', 'products.id');
                })
                ->get(['id', 'stock', 'cost']);

            if ($unlinked->isEmpty()) {
                $this->line("tenant {$tenantId}: كل المنتجات مربوطة بالفعل ✅");
                continue;
            }

            $this->info("tenant {$tenantId}: {$unlinked->count()} منتج ← مخزن #{$warehouse->id} ({$warehouse->name})");

            if ($apply) {
                // ✅ chunk على مستوى الـ collection مش جوه transaction كبيرة
                $chunks = $unlinked->chunk(500);

                foreach ($chunks as $chunk) {
                    DB::transaction(function () use ($chunk, $warehouse, $tenantId, $now) {
                        DB::table('product_warehouse')->insert(
                            $chunk->map(fn ($p) => [
                                'product_id'   => $p->id,
                                'warehouse_id' => $warehouse->id,
                                'stock'        => $p->stock ?? 0,
                                'cost'         => $p->cost ?? 0,
                                'tenant_id'    => $tenantId,
                                'created_at'   => $now,
                                'updated_at'   => $now,
                            ])->all()
                        );
                    });
                }
            }

            $totalLinked += $unlinked->count();
        }

        $this->info(($apply ? 'تم ربط ' : 'هيتربط (معاينة) ') . "{$totalLinked} منتج.");

        if (!$apply) {
            $this->warn('⚠️ معاينة فقط. أضف --apply للتنفيذ.');
        }

        return self::SUCCESS;
    }
}