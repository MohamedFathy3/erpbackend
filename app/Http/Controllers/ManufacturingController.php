<?php
namespace App\Http\Controllers;

use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\ManufacturingCostEntry;
use App\Models\ManufacturingOrder;
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
            $order->update(['produced_quantity' => $data['produced_quantity'], 'actual_cost' => $rawCost, 'status' => 'completed', 'completion_journal_entry_id' => $journal->id]);
            return $order->fresh(['product', 'bom', 'inspections', 'inventoryMovements', 'costEntries']);
        });

        return response()->json(['status' => true, 'message' => 'تم إكمال أمر الإنتاج وترحيل حركة المخزون والقيد المالي', 'data' => $result]);
    }
}
