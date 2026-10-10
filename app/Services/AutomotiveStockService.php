<?php

namespace App\Services;

use App\Models\AutomotiveService;
use App\Models\AutomotiveStockMovement;
use Illuminate\Support\Facades\DB;

/**
 * مخزون منتجات خدمة السيارات — الوحدة الوحيدة هي المتر.
 *
 * المصدر الوحيد للرصيد: automotive_services.stock_quantity (بالمتر)،
 * ويتم نسخه تلقائياً إلى products.stock للمنتج المرتبط.
 * كل حركة تُسجَّل في automotive_stock_movements (موجب = دخول، سالب = خروج).
 *
 * نقاط الربط مع بقية النظام:
 *   - المشتريات (استلام):  receiveByProduct($productId, $meters, [...])
 *   - فاتورة POS (بيع):    consumeByServiceId($serviceId, $quantity * $meterQuantity, [...])
 *   - مرتجع POS:           restore($service, $meters, [...])
 *   - أمر خدمة:            يتم داخل AutomotiveController
 */
class AutomotiveStockService
{
    public const UNIT = 'متر';

    /** شراء / استلام أمتار (يحدّث متوسط التكلفة لو تم تمرير unit_cost). */
    public function receive(AutomotiveService $service, float $meters, array $meta = []): ?AutomotiveStockMovement
    {
        return $this->apply($service, 'purchase', abs($meters), $meta);
    }

    /** نفس receive لكن بمعرّف المنتج (للمشتريات). يرجع null لو المنتج ليس منتج سيارات. */
    public function receiveByProduct(int $productId, float $meters, array $meta = []): ?AutomotiveStockMovement
    {
        $service = AutomotiveService::query()
            ->where('product_id', $productId)
            ->where('item_type', 'product')
            ->first();

        return $service ? $this->receive($service, $meters, $meta) : null;
    }

    /** بيع / استهلاك أمتار. يرفض (422) لو الرصيد لا يكفي. */
    public function consume(AutomotiveService $service, float $meters, array $meta = []): ?AutomotiveStockMovement
    {
        return $this->apply($service, 'sale', -abs($meters), $meta);
    }

    public function consumeByServiceId(int $serviceId, float $meters, array $meta = []): ?AutomotiveStockMovement
    {
        $service = AutomotiveService::query()->find($serviceId);

        return $service ? $this->consume($service, $meters, $meta) : null;
    }

    /** إرجاع أمتار للمخزن (مرتجع / إلغاء). */
    public function restore(AutomotiveService $service, float $meters, array $meta = []): ?AutomotiveStockMovement
    {
        return $this->apply($service, 'return', abs($meters), $meta);
    }

    /** تسوية بالزيادة أو النقص (delta موجب أو سالب). */
    public function adjust(AutomotiveService $service, float $delta, array $meta = []): ?AutomotiveStockMovement
    {
        return $this->apply($service, 'adjustment', $delta, $meta);
    }

    /**
     * يعيد للمخزن صافي ما خرج لمرجع معيّن (مثلاً أمر خدمة أُلغي).
     * آمن للتكرار: لو تم الإرجاع من قبل لا يفعل شيئاً.
     */
    public function restoreReference(string $referenceType, int $referenceId, array $meta = []): void
    {
        $groups = AutomotiveStockMovement::query()
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->whereIn('type', ['sale', 'return'])
            ->get()
            ->groupBy('service_id');

        foreach ($groups as $serviceId => $movements) {
            $net = (float) $movements->sum('meters'); // سالب = ما زال خارج المخزن
            if ($net < -0.0005 && ($service = AutomotiveService::query()->find($serviceId))) {
                $this->restore($service, abs($net), array_merge($meta, [
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                ]));
            }
        }
    }

    /**
     * الدالة الأساسية: تغيّر الرصيد بمقدار signed meters داخل transaction مع قفل الصف.
     *
     * @param array{unit_cost?: float|int|string, note?: string, reference_type?: string, reference_id?: int} $meta
     */
    public function apply(AutomotiveService $service, string $type, float $meters, array $meta = []): ?AutomotiveStockMovement
    {
        if ($service->item_type !== 'product' || abs($meters) < 0.0005) {
            return null; // الخدمات العادية لا تستهلك مخزوناً
        }

        return DB::transaction(function () use ($service, $type, $meters, $meta) {
            $locked = AutomotiveService::query()->whereKey($service->getKey())->lockForUpdate()->firstOrFail();

            $before = (float) $locked->stock_quantity;
            $after = round($before + $meters, 3);

            if ($after < 0) {
                abort(422, sprintf(
                    'المخزون غير كافٍ للمنتج «%s»: المتاح %s متر والمطلوب %s متر.',
                    $locked->name,
                    rtrim(rtrim(number_format($before, 3, '.', ''), '0'), '.'),
                    rtrim(rtrim(number_format(abs($meters), 3, '.', ''), '0'), '.')
                ));
            }

            $attributes = ['stock_quantity' => $after];
            $purchaseCost = (float) ($meta['unit_cost'] ?? 0);

            // متوسط مرجّح لتكلفة المتر عند الشراء
            if ($type === 'purchase' && $meters > 0 && $purchaseCost > 0) {
                $attributes['estimated_cost'] = round(
                    (($before * (float) $locked->estimated_cost) + ($meters * $purchaseCost)) / max($after, 0.001),
                    2
                );
            }

            $locked->update($attributes);
            $locked->product?->update(['stock' => $after]);

            $movement = AutomotiveStockMovement::create([
                'service_id' => $locked->id,
                'product_id' => $locked->product_id,
                'type' => $type,
                'meters' => $meters,
                'balance_after' => $after,
                'unit_cost' => $purchaseCost > 0 ? $purchaseCost : (float) $locked->estimated_cost,
                'reference_type' => $meta['reference_type'] ?? null,
                'reference_id' => $meta['reference_id'] ?? null,
                'user_id' => auth()->id(),
                'note' => $meta['note'] ?? null,
            ]);

            $service->refresh();

            return $movement;
        });
    }

    /** يسجّل حركة في السجل دون تغيير الرصيد (للرصيد الافتتاحي أو التعديل اليدوي من فورم الخدمة). */
    public function log(AutomotiveService $service, string $type, float $meters, array $meta = []): AutomotiveStockMovement
    {
        return AutomotiveStockMovement::create([
            'service_id' => $service->id,
            'product_id' => $service->product_id,
            'type' => $type,
            'meters' => $meters,
            'balance_after' => (float) $service->stock_quantity,
            'unit_cost' => (float) ($meta['unit_cost'] ?? $service->estimated_cost),
            'reference_type' => $meta['reference_type'] ?? null,
            'reference_id' => $meta['reference_id'] ?? null,
            'user_id' => auth()->id(),
            'note' => $meta['note'] ?? null,
        ]);
    }
}