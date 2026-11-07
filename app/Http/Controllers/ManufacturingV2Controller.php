<?php
namespace App\Http\Controllers;

use App\Manufacturing\Models\BomVersion;
use App\Manufacturing\Models\ManufacturingEquipment;
use App\Manufacturing\Models\ManufacturingOrder;
use App\Manufacturing\Services\ManufacturingService;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ManufacturingV2Controller extends Controller
{
    public function reports(Request $r) {
        $d=$r->validate(['from'=>'nullable|date','to'=>'nullable|date|after_or_equal:from','order_id'=>'nullable|integer','operation_id'=>'nullable|integer']);
        $base=fn($q)=>$q->when($d['from']??null,fn($x,$v)=>$x->whereDate('created_at','>=',$v))->when($d['to']??null,fn($x,$v)=>$x->whereDate('created_at','<=',$v))->when($d['order_id']??null,fn($x,$v)=>$x->where('manufacturing_order_id',$v));
        $scrap=$base(\App\Manufacturing\Models\ManufacturingStockMovement::with(['order.product','product','operation'])->where('movement_type','scrap'))->when($d['operation_id']??null,fn($q,$v)=>$q->where('manufacturing_operation_id',$v))->latest()->limit(500)->get();
        $quality=$base(\App\Manufacturing\Models\QualityInspection::with(['order.product','operation']))->when($d['operation_id']??null,fn($q,$v)=>$q->where('manufacturing_operation_id',$v))->latest()->limit(500)->get();
        $stops=$base(\App\Manufacturing\Models\ManufacturingOrderStatusHistory::with('order.product')->where('to_status','paused'))->latest()->limit(500)->get();
        $historyByOrder = \App\Manufacturing\Models\ManufacturingOrderStatusHistory::whereIn('manufacturing_order_id', $stops->pluck('manufacturing_order_id')->unique())->orderBy('id')->get()->groupBy('manufacturing_order_id');
        $stops->each(function ($stop) use ($historyByOrder): void {
            $next = ($historyByOrder->get($stop->manufacturing_order_id) ?? collect())->first(fn ($event) => $event->id > $stop->id);
            $stop->setAttribute('resumed_at', $next?->created_at);
            $stop->setAttribute('duration_minutes', $next ? round($stop->created_at->diffInSeconds($next->created_at) / 60, 2) : round($stop->created_at->diffInSeconds(now()) / 60, 2));
            $stop->setAttribute('still_stopped', !$next);
        });
        return response()->json(['data'=>['scrap'=>$scrap,'stops'=>$stops,'quality'=>$quality,'summary'=>['scrap_quantity'=>(float)$scrap->sum('quantity'),'stop_events'=>$stops->count(),'inspection_count'=>$quality->count(),'rejected_inspections'=>$quality->where('result','reject')->count(),'rework_inspections'=>$quality->where('result','rework')->count(),'stop_minutes'=>round((float)$stops->sum('duration_minutes'),2)]]]);
    }
    public function overview(Request $r)
    {
        $activeOrders = ManufacturingOrder::with(['materials.product'])
            ->whereIn('status', ['planned', 'reserved', 'in_progress', 'quality_hold', 'paused'])
            ->get();
        $service = $this->service();
        $materialShortageCount = 0;
        foreach ($activeOrders as $order) {
            $availability = $service->availability($order);
            $materialShortageCount += count(array_filter(
                $availability['items'],
                fn (array $item): bool => $item['shortage'] > 0,
            ));
        }

        $produced = (float) ManufacturingOrder::sum('produced_quantity');
        $scrapped = (float) ManufacturingOrder::sum('scrapped_quantity');
        $scrapRate = $produced + $scrapped > 0
            ? round($scrapped / ($produced + $scrapped) * 100, 2)
            : 0;

        return response()->json(['data' => [
            'kpis' => [
                'active_orders' => $activeOrders->count(),
                'planned_qty' => (float) $activeOrders->sum('planned_quantity'),
                'completed_today' => ManufacturingOrder::whereDate('updated_at', now()->toDateString())
                    ->where('status', 'completed')
                    ->count(),
                'material_shortage' => $materialShortageCount,
                'scrap_rate' => $scrapRate,
            ],
            'orders' => ManufacturingOrder::with('product')->latest()->limit(50)->get(),
            'boms' => BomVersion::with('product')->latest()->limit(50)->get(),
            'products' => Product::query()
                ->where(function ($query): void {
                    $query->whereIn('product_type', ['finished', 'semi_finished', 'good'])
                        ->orWhereNull('product_type');
                })
                ->latest()
                ->limit(100)
                ->get(),
        ]]);
    }

    private function service(): ManufacturingService { return new ManufacturingService(auth()->id(), auth()->user()?->tenant_id); }
    private function key(Request $r): string { return (string)($r->input('idempotency_key') ?: $r->header('Idempotency-Key')); }
    private function branchWarehouse(Request $r): void {
        if (!$r->filled('warehouse_id')) return;
        $warehouse = \DB::table('warehouses')->where('id', $r->warehouse_id)->first();
        abort_unless($warehouse, 404, 'Warehouse not found');
        $tenantId = $r->user()?->tenant_id ?: (app()->bound('currentTenantId') ? app('currentTenantId') : null);
        if ($tenantId && isset($warehouse->tenant_id)) {
            abort_unless((int) $warehouse->tenant_id === (int) $tenantId, 403, 'Warehouse is outside your workspace.');
        }
        $branch = (int) ($r->input('branch_id') ?: $warehouse->branch_id);
        abort_unless((int) $warehouse->branch_id === $branch, 403, 'Warehouse is outside branch');
        $user = $r->user();
        if ($user && !($user->super_admin ?? false) && $user->branch_id) {
            abort_unless((int) $user->branch_id === $branch, 403, 'You may only use your assigned branch.');
        }
    }

    private function authorizeBranch(Request $request): void {
        $user = $request->user();
        $branchId = $request->input('branch_id');
        if ($user && $branchId && !($user->super_admin ?? false) && $user->branch_id) {
            abort_unless((int) $user->branch_id === (int) $branchId, 403, 'You may only use your assigned branch.');
        }
    }

    public function bomIndex(Request $r) { return BomVersion::with(['product','items.component','items.childBom'])->when($r->branch_id,fn($q)=>$q->where('branch_id',$r->branch_id))->latest()->paginate($r->integer('per_page',25)); }
    public function bomStore(Request $r) {
        $d=$r->validate([
            'product_id'=>'required|exists:products,id',
            'branch_id'=>'nullable|exists:branches,id',
            'version'=>'required|string|max:50',
            'status'=>['nullable',Rule::in(['draft','active','obsolete'])],
            'yield_quantity'=>'nullable|numeric|gt:0',
            'effective_from'=>'nullable|date',
            'effective_to'=>'nullable|date|after_or_equal:effective_from',
            'notes'=>'nullable|string',
            'items'=>'required|array|min:1',
            'items.*.component_product_id'=>'required|exists:products,id',
            'items.*.quantity'=>'required|numeric|gt:0',
            'items.*.scrap_percent'=>'nullable|numeric|min:0|max:100',
            'items.*.unit'=>'nullable|string|max:32',
            'items.*.is_optional'=>'nullable|boolean','items.*.specifications'=>'nullable|array','items.*.specifications.width'=>'nullable|numeric|gt:0','items.*.specifications.length'=>'nullable|numeric|gt:0','items.*.specifications.thickness'=>'nullable|numeric|gt:0','items.*.specifications.unit'=>'nullable|string|max:16','items.*.specifications.material_grade'=>'nullable|string|max:100','items.*.specifications.finish'=>'nullable|string|max:100',
            'items.*.parent_item_id'=>'nullable|exists:bom_items,id',
            'items.*.child_bom_version_id'=>'nullable|exists:bom_versions,id',
            'items.*.notes'=>'nullable|string',
        ]);
        $this->authorizeBranch($r);
        return \DB::transaction(function()use($d){
            $product = Product::lockForUpdate()->findOrFail($d['product_id']);
            $duplicate = BomVersion::where('product_id', $product->id)
                ->where('version', $d['version'])
                ->where('branch_id', $d['branch_id'] ?? null)
                ->exists();
            abort_if($duplicate, 422, 'This BOM version already exists for the product and branch.');
            if (($d['status'] ?? 'draft') === 'active') {
                BomVersion::where('product_id', $product->id)
                    ->where('branch_id', $d['branch_id'] ?? null)
                    ->where('status', 'active')
                    ->update(['status' => 'obsolete']);
            }
            $items=$this->normalizeBomItems($d['items']); unset($d['items']);
            $actorId = auth()->id();
            $creatorId = $actorId && \DB::table('users')->where('id', $actorId)->exists() ? $actorId : null;
            $bom=BomVersion::create($d+['created_by'=>$creatorId]);
            foreach($items as $item) $bom->items()->create($item);
            return response()->json($bom->load(['product','items.component','items.childBom']),201);
        });
    }
    public function bomShow(BomVersion $bomVersion) { return $bomVersion->load(['product','items.component','items.childBom']); }
    public function bomUpdate(Request $r, BomVersion $bomVersion)
    {
        abort_if($bomVersion->status === 'active' || $bomVersion->orders()->exists(), 422, 'BOM revisions used by orders are immutable; create a new version.');
        $d = $r->validate([
            'version' => 'sometimes|string|max:50',
            'status' => ['sometimes', Rule::in(['draft', 'active', 'obsolete'])],
            'yield_quantity' => 'sometimes|numeric|gt:0',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'notes' => 'nullable|string',
            'items' => 'sometimes|array|min:1',
            'items.*.component_product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.scrap_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.unit' => 'nullable|string|max:32',
            'items.*.is_optional' => 'nullable|boolean',
            'items.*.child_bom_version_id' => 'nullable|exists:bom_versions,id',
        ]);
        return \DB::transaction(function () use ($d, $bomVersion) {
            $items = isset($d['items']) ? $this->normalizeBomItems($d['items']) : null;
            unset($d['items']);
            if (($d['status'] ?? null) === 'active') {
                BomVersion::where('product_id', $bomVersion->product_id)->where('branch_id', $bomVersion->branch_id)
                    ->where('id', '!=', $bomVersion->id)->where('status', 'active')->update(['status' => 'obsolete']);
            }
            $bomVersion->update($d);
            if ($items !== null) {
                $bomVersion->items()->delete();
                foreach ($items as $item) $bomVersion->items()->create($item);
            }
            return $bomVersion->load(['product', 'items.component', 'items.childBom']);
        });
    }

    private function normalizeBomItems(array $items): array
    {
        foreach ($items as &$item) {
            $component = Product::findOrFail($item['component_product_id']);
            abort_unless(in_array($component->product_type, ['material', 'semi_finished'], true), 422,
                'BOM components must be an inventory material or a semi-finished product.');
            $baseUnit = $component->base_unit;
            $stockUnit = $component->stock_unit;
            $sourceUnit = $baseUnit ?: $stockUnit;
            abort_unless($sourceUnit, 422, "Set a base or stock unit on product {$component->name} before adding it to a BOM.");
            if (!empty($item['unit']) && !in_array($item['unit'], array_filter([$baseUnit, $stockUnit]), true)) {
                abort(422, "BOM unit must match the selected product's base or stock unit ({$sourceUnit}).");
            }
            $item['unit'] = $sourceUnit;
            unset($item['specifications']); // dimensions, colors, and material attributes live on the product record.
        }
        unset($item);
        return $items;
    }

    public function bomDestroy(BomVersion $bomVersion) { abort_if($bomVersion->status==='active'||$bomVersion->orders()->exists(),422,'Active or used BOM revisions are immutable');$bomVersion->delete();return response()->noContent(); }

    public function productStore(Request $r) { $d=$r->validate(['name'=>'required|string','sku'=>'nullable|string','product_type'=>['required',Rule::in(['good','service','material','semi_finished','finished'])],'stock_unit'=>'nullable|string','base_unit'=>'nullable|string','conversion_factor'=>'nullable|numeric|gt:0','tracking'=>['nullable',Rule::in(['none','lot','serial'])],'dimensions'=>'nullable|array','price'=>'nullable|numeric','cost'=>'nullable|numeric']);return Product::create($d); }
    public function productUpdate(Request $r,Product $product) { $d=$r->validate(['name'=>'sometimes|string','product_type'=>[Rule::in(['good','service','material','semi_finished','finished'])],'stock_unit'=>'nullable|string','base_unit'=>'nullable|string','conversion_factor'=>'nullable|numeric|gt:0','tracking'=>[Rule::in(['none','lot','serial'])],'dimensions'=>'nullable|array']);$product->update($d);return $product->fresh(); }
    public function productIndex(Request $r) { return Product::with(['units.unit','units.colors.color'])->where(function($q){$q->whereIn('product_type',['good','service','material','semi_finished','finished'])->orWhereNull('product_type');})->paginate($r->integer('per_page',250)); }

    public function equipmentIndex() { return ManufacturingEquipment::latest()->paginate(25); }
    public function equipmentStore(Request $r) { return ManufacturingEquipment::create($r->validate(['code'=>'required|string|unique:manufacturing_equipment,code','name'=>'required|string','branch_id'=>'nullable|exists:branches,id','status'=>'nullable|string','hourly_rate'=>'nullable|numeric|min:0','notes'=>'nullable|string'])); }
    public function equipmentUpdate(Request $r,ManufacturingEquipment $equipment) { $d=$r->validate(['name'=>'sometimes|string','status'=>'sometimes|string','branch_id'=>'nullable|exists:branches,id','hourly_rate'=>'nullable|numeric|min:0','notes'=>'nullable|string']);$equipment->update($d);return $equipment->fresh(); }
    public function equipmentDestroy(ManufacturingEquipment $equipment) { $equipment->delete();return response()->noContent(); }

    public function orderIndex(Request $r) { return ManufacturingOrder::with(['product','materials','operations'])->when($r->branch_id,fn($q)=>$q->where('branch_id',$r->branch_id))->latest()->limit(100)->get(); }
    public function orderStore(Request $r) { $payload=$r->all(); if(isset($payload['quantity']) && !isset($payload['planned_quantity'])) $payload['planned_quantity']=$payload['quantity']; $d=validator($payload,['product_id'=>'required|exists:products,id','bom_version_id'=>'nullable|exists:bom_versions,id','branch_id'=>'nullable|exists:branches,id','warehouse_id'=>'required|exists:warehouses,id','source_invoice_id'=>'nullable|exists:invoices,id','source_invoice_item_id'=>'nullable|exists:invoice_items,id','source_sales_order_id'=>'nullable|integer','planned_quantity'=>'required|numeric|gt:0','dimensions'=>'nullable|array','dimensions.width'=>'nullable|numeric|gt:0','dimensions.height'=>'nullable|numeric|gt:0','dimensions.depth'=>'nullable|numeric|gt:0','dimensions.unit'=>'nullable|string|max:16','planned_at'=>'nullable|date','operations'=>'nullable|array','operations.*.name'=>'required|string','operations.*.sequence'=>'nullable|integer|min:1','operations.*.equipment_id'=>'nullable|exists:manufacturing_equipment,id','operations.*.planned_minutes'=>'nullable|numeric|min:0','notes'=>'nullable|string'])->validate(); $this->branchWarehouse($r); return response()->json($this->service()->createOrder($d),201); }
    public function orderFromInvoice(Request $r, int $invoice) { $d=$r->validate(['invoice_item_id'=>'required|integer','warehouse_id'=>'required|exists:warehouses,id','operations'=>'nullable|array','operations.*.name'=>'required|string','operations.*.sequence'=>'nullable|integer|min:1','operations.*.equipment_id'=>'nullable|exists:manufacturing_equipment,id','operations.*.planned_minutes'=>'nullable|numeric|min:0']); $r->merge(['warehouse_id'=>$d['warehouse_id']]); $this->branchWarehouse($r); return response()->json($this->service()->createOrderFromInvoice($invoice,$d['invoice_item_id'],$d['warehouse_id'],$d['operations']??[]),201); }
    public function orderShow(ManufacturingOrder $manufacturingOrder) { return $manufacturingOrder->load(['product','bomVersion','materials.product','operations','statusHistory','movements','costs','qualityInspections']); }
    public function availability(ManufacturingOrder $o) { return $this->service()->availability($o); }
    public function start(Request $r,ManufacturingOrder $o) { $key=$r->validate(['idempotency_key'=>'required|string|max:255'])['idempotency_key']; return $this->service()->start($o,$key); }
    public function pause(Request $r,ManufacturingOrder $o) { $d=$r->validate(['idempotency_key'=>'required|string|max:255','reason'=>'required|string|min:3|max:2000']); return $this->service()->pause($o,$d['reason'],$d['idempotency_key']); }
    public function resume(Request $r,ManufacturingOrder $o) { $key=$r->validate(['idempotency_key'=>'required|string|max:255'])['idempotency_key']; return $this->service()->resume($o,$key); }
    public function reserve(Request $r,ManufacturingOrder $o) { $key=$r->validate(['idempotency_key'=>'required|string|max:255'])['idempotency_key']; return $this->service()->reserve($o,$key); }
    public function issue(Request $r,ManufacturingOrder $o) { $d=$r->validate(['idempotency_key'=>'required|string','items'=>'required|array|min:1','items.*.material_id'=>'required|integer','items.*.quantity'=>'required|numeric|gt:0']);return $this->service()->issue($o,$d['items'],$d['idempotency_key']); }
    public function returnMaterial(Request $r,ManufacturingOrder $o) { $d=$r->validate(['idempotency_key'=>'required|string','items'=>'required|array','items.*.material_id'=>'required|integer','items.*.quantity'=>'required|numeric|gt:0']);return $this->service()->returnMaterial($o,$d['items'],$d['idempotency_key']); }
    public function scrap(Request $r,ManufacturingOrder $o) { $d=$r->validate(['idempotency_key'=>'required|string','items'=>'required|array|min:1','items.*.material_id'=>'required|integer','items.*.quantity'=>'required|numeric|gt:0','operation_id'=>'nullable|integer','reason'=>'required|string|min:3|max:500']);return $this->service()->scrap($o,$d['items'],$d['idempotency_key'],$d['operation_id']??null,$d['reason']); }
    public function operation(Request $r,ManufacturingOrder $o, int $operation) { $d=$r->validate(['idempotency_key'=>'required|string','action'=>['required',Rule::in(['start','complete','labor','machine'])],'actual_minutes'=>'nullable|numeric|min:0','minutes'=>'nullable|numeric|min:0','cost'=>'nullable|numeric|min:0']);return $this->service()->operation($o,$operation,$d['action'],$d,$d['idempotency_key']); }
    public function quality(Request $r,ManufacturingOrder $o) { $d=$r->validate(['idempotency_key'=>'required|string','result'=>['required',Rule::in(['pass','reject','rework'])],'quantity'=>'nullable|numeric|gt:0','manufacturing_operation_id'=>'nullable|exists:manufacturing_operations,id','reason'=>'nullable|string']);return $this->service()->quality($o,$d,$d['idempotency_key']); }
    public function receive(Request $r,ManufacturingOrder $o) { $d=$r->validate(['idempotency_key'=>'required|string|max:255','quantity'=>'required|numeric|gt:0']);return $this->service()->receive($o,$d['quantity'],$d['idempotency_key']); }
    public function cancel(Request $r,ManufacturingOrder $o) { return $this->service()->cancel($o,$r->validate(['idempotency_key'=>'required|string'])['idempotency_key']); }
    public function close(Request $r,ManufacturingOrder $o) { return $this->service()->close($o,$r->validate(['idempotency_key'=>'required|string'])['idempotency_key']); }
}
