<?php
namespace App\Http\Controllers;

use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\ManufacturingCostEntry;
use App\Models\ManufacturingOrder;
use App\Models\ManufacturingBom;
use App\Models\ManufacturingWorkCenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ManufacturingController extends Controller
{
    public function dashboard(Request $request)
    {
        $orders = ManufacturingOrder::with(['product', 'bom', 'inspections'])->latest()->paginate($request->integer('per_page', 20));
        return response()->json(['status' => true, 'data' => ['orders' => $orders, 'boms_count' => \App\Models\ManufacturingBom::count(), 'work_centers_count' => \App\Models\ManufacturingWorkCenter::where('active', true)->count(), 'in_progress' => ManufacturingOrder::where('status', 'in_progress')->count(), 'quality_hold' => ManufacturingOrder::where('status', 'quality_hold')->count()]]);
    }

    public function boms() { return response()->json(['status' => true, 'data' => \App\Models\ManufacturingBom::with(['product', 'items.product'])->where('status', 'active')->get()]); }
    public function orders(Request $request) { return response()->json(['status' => true, 'data' => ManufacturingOrder::with(['product', 'bom'])->latest()->paginate($request->integer('per_page', 20))]); }

    public function storeBom(Request $request)
    {
        $data = $request->validate(['product_id' => ['required', 'exists:products,id'], 'code' => ['required', 'string', 'max:100', 'unique:manufacturing_boms,code'], 'name' => ['required', 'string', 'max:150'], 'status' => ['nullable', Rule::in(['draft', 'active', 'archived'])], 'planned_unit_cost' => ['nullable', 'numeric', 'min:0'], 'items' => ['required', 'array', 'min:1'], 'items.*.product_id' => ['required', 'exists:products,id'], 'items.*.quantity' => ['required', 'numeric', 'gt:0'], 'items.*.scrap_percent' => ['nullable', 'numeric', 'min:0']]);
        $bom = DB::transaction(function () use ($data) { $bom = ManufacturingBom::create(collect($data)->except('items')->toArray()); $bom->items()->createMany($data['items']); return $bom->load('items.product'); });
        return response()->json(['status' => true, 'data' => $bom], 201);
    }

    public function updateBom(Request $request, ManufacturingBom $bom)
    {
        $data = $request->validate(['product_id' => ['sometimes', 'exists:products,id'], 'code' => ['sometimes', 'string', 'max:100', Rule::unique('manufacturing_boms', 'code')->ignore($bom->id)], 'name' => ['sometimes', 'string', 'max:150'], 'status' => ['nullable', Rule::in(['draft', 'active', 'archived'])], 'planned_unit_cost' => ['nullable', 'numeric', 'min:0'], 'items' => ['sometimes', 'array', 'min:1']]);
        $updated = DB::transaction(function () use ($data, $bom) { if (array_key_exists('items', $data)) { $bom->items()->delete(); $bom->items()->createMany($data['items']); } $bom->update(collect($data)->except('items')->toArray()); return $bom->fresh('items.product'); });
        return response()->json(['status' => true, 'data' => $updated]);
    }

    public function destroyBom(ManufacturingBom $bom)
    {
        abort_if($bom->orders()->exists(), 422, 'لا يمكن حذف BOM مرتبطة بأوامر إنتاج');
        $bom->update(['status' => 'archived']);
        return response()->json(['status' => true, 'message' => 'تم أرشفة BOM']);
    }

    public function storeWorkCenter(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:100', 'unique:manufacturing_work_centers,code'], 'name' => ['required', 'string', 'max:150'], 'hourly_rate' => ['nullable', 'numeric', 'min:0'], 'capacity_hours_per_day' => ['nullable', 'numeric', 'min:0']]);
        return response()->json(['status' => true, 'data' => ManufacturingWorkCenter::create($data)], 201);
    }

    public function storeOrder(Request $request)
    {
        $data = $request->validate(['product_id' => ['required', 'exists:products,id'], 'bom_id' => ['nullable', 'exists:manufacturing_boms,id'], 'warehouse_id' => ['nullable', 'exists:warehouses,id'], 'planned_quantity' => ['required', 'numeric', 'gt:0'], 'planned_start_date' => ['nullable', 'date'], 'planned_end_date' => ['nullable', 'date', 'after_or_equal:planned_start_date'], 'planned_cost' => ['nullable', 'numeric', 'min:0'], 'notes' => ['nullable', 'string']]);
        $data['order_number'] = 'MO-' . now()->format('YmdHis') . '-' . random_int(100, 999);
        return response()->json(['status' => true, 'data' => ManufacturingOrder::create($data)], 201);
    }

    public function updateOrder(Request $request, ManufacturingOrder $order)
    {
        abort_if(in_array($order->status, ['completed', 'cancelled']), 422, 'لا يمكن تعديل أمر مغلق');
        $data = $request->validate(['planned_quantity' => ['sometimes', 'numeric', 'gt:0'], 'bom_id' => ['nullable', 'exists:manufacturing_boms,id'], 'warehouse_id' => ['nullable', 'exists:warehouses,id'], 'planned_start_date' => ['nullable', 'date'], 'planned_end_date' => ['nullable', 'date', 'after_or_equal:planned_start_date'], 'planned_cost' => ['nullable', 'numeric', 'min:0'], 'notes' => ['nullable', 'string'], 'status' => ['nullable', Rule::in(['draft', 'planned'])]]);
        $order->update($data);
        return response()->json(['status' => true, 'data' => $order->fresh(['product', 'bom'])]);
    }

    public function start(ManufacturingOrder $order)
    {
        abort_if(in_array($order->status, ['completed', 'cancelled']), 422, 'لا يمكن تشغيل أمر مغلق');
        $order->update(['status' => 'in_progress']);
        return response()->json(['status' => true, 'data' => $order->fresh(['product', 'bom'])]);
    }

    public function complete(Request $request, ManufacturingOrder $order)
    {
        $data = $request->validate([
            'produced_quantity' => ['required', 'numeric', 'gt:0'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'inventory_account_id' => ['required', 'exists:accounts,id'],
            'work_in_progress_account_id' => ['required', 'exists:accounts,id'],
            'quality_status' => ['required', Rule::in(['passed', 'conditional'])],
            'note' => ['nullable', 'string'],
        ]);

        $result = DB::transaction(function () use ($data, $order) {
            $order = ManufacturingOrder::with('bom.items.product')->lockForUpdate()->findOrFail($order->id);
            abort_if(!in_array($order->status, ['planned', 'in_progress', 'quality_hold']), 422, 'حالة أمر الإنتاج لا تسمح بالإكمال');
            abort_if(!$order->bom, 422, 'أمر الإنتاج ليس له BOM فعالة');

            $rawCost = 0;
            foreach ($order->bom->items as $bomItem) {
                $required = (float) $bomItem->quantity * (float) $data['produced_quantity'] * (1 + ((float) $bomItem->scrap_percent / 100));
                $product = $bomItem->product()->lockForUpdate()->firstOrFail();
                abort_if((float) $product->stock < $required, 422, "المخزون غير كافٍ للخامة: {$product->name}");
                $unitCost = (float) ($product->cost ?? 0);
                $totalCost = $required * $unitCost;
                $product->decrement('stock', $required);
                InventoryMovement::create(['warehouse_id' => $data['warehouse_id'], 'product_id' => $product->id, 'manufacturing_order_id' => $order->id, 'reference_type' => ManufacturingOrder::class, 'reference_id' => $order->id, 'type' => 'issue', 'quantity' => $required, 'unit_cost' => $unitCost, 'total_cost' => $totalCost, 'note' => $data['note'] ?? 'صرف خامات لأمر إنتاج']);
                $rawCost += $totalCost;
            }

            $finished = $order->product()->lockForUpdate()->firstOrFail();
            $finished->increment('stock', $data['produced_quantity']);
            $unitFinishedCost = $rawCost / (float) $data['produced_quantity'];
            InventoryMovement::create(['warehouse_id' => $data['warehouse_id'], 'product_id' => $finished->id, 'manufacturing_order_id' => $order->id, 'reference_type' => ManufacturingOrder::class, 'reference_id' => $order->id, 'type' => 'receipt', 'quantity' => $data['produced_quantity'], 'unit_cost' => $unitFinishedCost, 'total_cost' => $rawCost, 'note' => 'إضافة منتج تام من أمر إنتاج']);

            $journal = JournalEntry::create(['entry_date' => now()->toDateString(), 'description_ar' => "تكلفة إنتاج {$order->order_number}", 'description_en' => "Production completion {$order->order_number}", 'status' => 'posted']);
            $journal->lines()->createMany([
                ['account_id' => $data['inventory_account_id'], 'debit' => $rawCost, 'credit' => 0, 'description' => 'إضافة المنتج التام للمخزون'],
                ['account_id' => $data['work_in_progress_account_id'], 'debit' => 0, 'credit' => $rawCost, 'description' => 'إقفال تكلفة الإنتاج تحت التشغيل'],
            ]);
            ManufacturingCostEntry::create(['manufacturing_order_id' => $order->id, 'cost_type' => 'material', 'amount' => $rawCost, 'journal_entry_id' => $journal->id, 'description' => 'تكلفة الخامات الفعلية']);
            \App\Models\ManufacturingQualityInspection::create(['manufacturing_order_id' => $order->id, 'inspection_number' => 'QI-' . now()->format('YmdHis') . '-' . $order->id, 'result' => $data['quality_status'], 'accepted_quantity' => $data['produced_quantity'], 'rejected_quantity' => 0, 'inspected_at' => now()]);
            $order->update(['produced_quantity' => $data['produced_quantity'], 'actual_cost' => $rawCost, 'status' => 'completed', 'completion_journal_entry_id' => $journal->id]);
            \App\Models\WorkflowTransaction::capture('manufacturing:complete:' . $order->id, $order, 'manufacturing_completed', ['raw_cost' => $rawCost, 'produced_quantity' => $data['produced_quantity']], $journal->id);
            return $order->fresh(['product', 'bom', 'inspections', 'inventoryMovements', 'costEntries']);
        });

        return response()->json(['status' => true, 'message' => 'تم إكمال أمر الإنتاج وترحيل حركة المخزون والقيد المالي', 'data' => $result]);
    }
}
