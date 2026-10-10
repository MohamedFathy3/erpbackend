<?php

namespace App\Http\Controllers;

use App\Models\AutomotiveService;
use App\Models\AutomotiveServiceOrder;
use App\Models\AutomotiveServiceOrderItem;
use App\Models\AutomotiveVehicle;
use App\Models\Product;
use App\Models\Invoice;
use App\Models\Admin;
use App\Models\AutomotiveWarranty;
use App\Models\AutomotiveStockMovement;
use App\Services\AutomotiveStockService;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AutomotiveController extends BaseController
{
    public function vehicles(Request $request)
    {
        $query = AutomotiveVehicle::with('customer')->latest();
        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(fn ($q) => $q->where('plate_number', 'like', "%{$search}%")
                ->orWhere('vin', 'like', "%{$search}%")
                ->orWhere('make', 'like', "%{$search}%")
                ->orWhere('model', 'like', "%{$search}%"));
        }
        if ($request->filled('customer_id')) $query->where('customer_id', $request->integer('customer_id'));
        return response()->json(['status' => true, 'data' => $query->paginate($request->integer('per_page', 25))]);
    }

    public function storeVehicle(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'plate_number' => ['nullable', 'string', 'max:50'],
            'vin' => ['nullable', 'string', 'max:100'],
            'make' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
            'model_year' => ['nullable', 'integer', 'min:1900', 'max:2200'],
            'color' => ['nullable', 'string', 'max:50'],
            'fuel_type' => ['nullable', 'string', 'max:50'],
            'external_catalog_id' => ['nullable', 'string', 'max:100'],
            'current_mileage' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);
        $vehicle = AutomotiveVehicle::create($data);
        return response()->json(['status' => true, 'data' => $vehicle->load('customer')], 201);
    }

    public function showVehicle(AutomotiveVehicle $vehicle)
    {
        return response()->json(['status' => true, 'data' => $vehicle->load(['customer', 'serviceOrders.technicians', 'serviceOrders.items'])]);
    }

    public function updateVehicle(Request $request, AutomotiveVehicle $vehicle)
    {
        $data = $request->validate([
            'customer_id' => ['sometimes', 'integer', 'exists:customers,id'],
            'plate_number' => ['nullable', 'string', 'max:50'], 'vin' => ['nullable', 'string', 'max:100'],
            'make' => ['sometimes', 'string', 'max:100'], 'model' => ['sometimes', 'string', 'max:100'],
            'model_year' => ['nullable', 'integer', 'min:1900', 'max:2200'], 'color' => ['nullable', 'string', 'max:50'],
            'fuel_type' => ['nullable', 'string', 'max:50'], 'external_catalog_id' => ['nullable', 'string', 'max:100'],
            'current_mileage' => ['nullable', 'numeric', 'min:0'], 'notes' => ['nullable', 'string'],
        ]);
        $vehicle->update($data);
        return response()->json(['status' => true, 'data' => $vehicle->fresh()->load('customer')]);
    }

    public function services(Request $request)
    {
        $query = AutomotiveService::with('product')->latest();
        if ($request->has('active')) $query->where('active', $request->boolean('active'));
        if ($request->filled('search')) $query->where(fn ($q) => $q->where('name', 'like', '%' . $request->string('search') . '%')->orWhere('code', 'like', '%' . $request->string('search') . '%'));
        return response()->json(['status' => true, 'data' => $query->paginate($request->integer('per_page', 25))]);
    }

    private function stock(): AutomotiveStockService
    {
        return app(AutomotiveStockService::class);
    }

    /** مخزون المنتج المرتبط: المنتجات بالمتر (كسور مسموحة)، والخدمات غير محدودة. */
    private function productStockValue(AutomotiveService $service): float|int
    {
        return $service->item_type === 'product'
            ? round((float) ($service->stock_quantity ?? 0), 3)
            : 999999;
    }

    public function storeService(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'item_type' => ['nullable', Rule::in(['service', 'product'])],
            'unit' => ['nullable', 'string', 'max:30'],
            'has_fixed_price' => ['boolean'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'small_vehicle_quantity' => ['nullable', 'numeric', 'min:0.001'],
            'large_vehicle_quantity' => ['nullable', 'numeric', 'min:0.001'],
            'small_vehicle_price' => ['nullable', 'numeric', 'min:0'],
            'large_vehicle_price' => ['nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['nullable', 'numeric', 'min:0'],
            'estimated_minutes' => ['nullable', 'integer', 'min:1'],
            'warranty_eligible' => ['boolean'],
            'active' => ['boolean'],
        ]);

        $data['active'] = $request->boolean('active', true);
        $data['item_type'] = $data['item_type'] ?? 'service';

        if ($data['item_type'] === 'product') {
            $data['unit'] = AutomotiveStockService::UNIT;           // المنتجات دائماً بالمتر
            $data['stock_quantity'] = $data['stock_quantity'] ?? 0; // الرصيد الافتتاحي بالمتر
        } else {
            $data['stock_quantity'] = 0;                            // الخدمات لا مخزون لها
        }

        // منتج → سعر ثابت | خدمة بسعر > 0 → ثابت | خدمة بدون سعر → يدوي في POS
        $data = $this->applyPricingMode($data, $request, null);

        $service = DB::transaction(function () use ($data) {
            $service = AutomotiveService::create($data);

            $product = Product::create([
                'name' => $service->name,
                'description' => $service->description,
                'sku' => 'AUTO-' . $service->code,
                'price' => $this->resolveProductPrice($service),
                'cost' => $service->estimated_cost,
                'stock' => $this->productStockValue($service),
                'active' => $service->active ?? true,
            ]);

            $service->update(['product_id' => $product->id]);
            $service = $service->fresh();

            if ($service->item_type === 'product' && (float) $service->stock_quantity > 0) {
                $this->stock()->log($service, 'initial', (float) $service->stock_quantity, [
                    'unit_cost' => (float) $service->estimated_cost,
                    'note' => 'رصيد افتتاحي (بالمتر)',
                ]);
            }

            return $service->load('product');
        });

        return response()->json(['status' => true, 'data' => $service], 201);
    }

    public function updateService(Request $request, AutomotiveService $service)
    {
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:50'],
            'name' => ['sometimes', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'item_type' => ['sometimes', Rule::in(['service', 'product'])],
            'unit' => ['nullable', 'string', 'max:30'],
            'has_fixed_price' => ['boolean'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'small_vehicle_quantity' => ['nullable', 'numeric', 'min:0.001'],
            'large_vehicle_quantity' => ['nullable', 'numeric', 'min:0.001'],
            'small_vehicle_price' => ['nullable', 'numeric', 'min:0'],
            'large_vehicle_price' => ['nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['nullable', 'numeric', 'min:0'],
            'estimated_minutes' => ['nullable', 'integer', 'min:1'],
            'warranty_eligible' => ['boolean'],
            'active' => ['boolean'],
        ]);

        $oldStock = (float) $service->stock_quantity;
        $newType = $data['item_type'] ?? $service->item_type;

        if ($newType === 'product') {
            $data['unit'] = AutomotiveStockService::UNIT;
        } else {
            unset($data['stock_quantity']);
        }

        if ($request->hasAny(['selling_price', 'has_fixed_price', 'item_type'])) {
            $data = $this->applyPricingMode($data, $request, $service);
        }

        $service->update($data);
        $service->refresh();

        // تعديل الرصيد يدوياً من فورم الخدمة = تسوية مسجلة في السجل
        if ($service->item_type === 'product') {
            $delta = round((float) $service->stock_quantity - $oldStock, 3);
            if (abs($delta) >= 0.001) {
                $this->stock()->log($service, 'adjustment', $delta, ['note' => 'تعديل الرصيد من شاشة الخدمات']);
            }
        }

        if ($service->product) {
            $service->product->update([
                'name' => $service->name,
                'description' => $service->description,
                'price' => $this->resolveProductPrice($service),
                'cost' => $service->estimated_cost,
                'stock' => $this->productStockValue($service),
                'active' => $service->active ?? true,
            ]);
        }

        return response()->json(['status' => true, 'data' => $service->fresh()->load('product')]);
    }

    /**
     * إضافة / خصم أمتار من مخزن منتج (شراء أو تسوية أو مرتجع).
     * POST /automotive/services/{service}/stock  { meters, type?, unit_cost?, note? }
     */
    public function adjustStock(Request $request, AutomotiveService $service)
    {
        abort_unless($service->item_type === 'product', 422, 'المخزون بالمتر متاح للمنتجات فقط.');

        $data = $request->validate([
            'meters' => ['required', 'numeric', 'not_in:0'],
            'type' => ['nullable', Rule::in(['purchase', 'adjustment', 'return'])],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $meters = (float) $data['meters'];
        $type = $data['type'] ?? ($meters > 0 ? 'purchase' : 'adjustment');

        $movement = $this->stock()->apply($service, $type, $meters, [
            'unit_cost' => $data['unit_cost'] ?? 0,
            'note' => $data['note'] ?? null,
        ]);

        return response()->json([
            'status' => true,
            'data' => ['service' => $service->fresh()->load('product'), 'movement' => $movement],
        ], 201);
    }

    /** سجل حركة المخزون (دخول / خروج بالمتر). */
    public function stockMovements(Request $request)
    {
        $query = AutomotiveStockMovement::with('service:id,code,name,name_ar')->latest('id');
        foreach (['service_id', 'type', 'reference_type', 'reference_id'] as $field) {
            if ($request->filled($field)) $query->where($field, $request->input($field));
        }
        if ($request->filled('from')) $query->where('created_at', '>=', $request->date('from')->startOfDay());
        if ($request->filled('to')) $query->where('created_at', '<=', $request->date('to')->endOfDay());

        return response()->json(['status' => true, 'data' => $query->paginate($request->integer('per_page', 25))]);
    }

    /**
     * يحدد نمط التسعير: سعر ثابت يظهر تلقائياً في POS، أو سعر يدوي يدخله الكاشير.
     */
    private function applyPricingMode(array $data, Request $request, ?AutomotiveService $existing): array
    {
        $itemType = $data['item_type'] ?? $existing?->item_type ?? 'service';
        $price = array_key_exists('selling_price', $data)
            ? (float) ($data['selling_price'] ?? 0)
            : (float) ($existing?->selling_price ?? 0);

        if ($itemType === 'product') {
            $fixed = true;
        } else {
            $fixed = $price > 0;
            if ($request->has('has_fixed_price') && !$request->boolean('has_fixed_price')) {
                $fixed = false;
            }
        }

        $data['has_fixed_price'] = $fixed;

        if (!$fixed) {
            $data['selling_price'] = 0;
            $data['small_vehicle_price'] = null;
            $data['large_vehicle_price'] = null;
        }

        return $data;
    }

    /**
     * سعر المنتج المرتبط بالخدمة في POS (0 لو السعر يدوي).
     */
    private function resolveProductPrice(AutomotiveService $service): float
    {
        if (!$service->has_fixed_price) {
            return 0;
        }
        foreach ([$service->small_vehicle_price, $service->selling_price] as $candidate) {
            if ((float) $candidate > 0) {
                return (float) $candidate;
            }
        }
        return 0;
    }

    public function orders(Request $request)
    {
        $query = AutomotiveServiceOrder::with(['customer', 'vehicle', 'advisor', 'technicians', 'items'])->latest();
        foreach (['status', 'priority', 'customer_id', 'vehicle_id', 'advisor_id'] as $field) if ($request->filled($field)) $query->where($field, $request->input($field));
        return response()->json(['status' => true, 'data' => $query->paginate($request->integer('per_page', 25))]);
    }

    public function storeOrder(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'], 'vehicle_id' => ['required', 'integer', 'exists:automotive_vehicles,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'], 'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'], 'advisor_id' => ['nullable', 'integer', 'exists:employees,id'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])], 'odometer' => ['nullable', 'numeric', 'min:0'],
            'customer_request' => ['nullable', 'string'], 'internal_notes' => ['nullable', 'string'], 'promised_at' => ['nullable', 'date'],
            'warranty' => ['nullable', 'array'], 'warranty.policy_name' => ['required_with:warranty', 'string', 'max:255'], 'warranty.starts_at' => ['required_with:warranty', 'date'], 'warranty.ends_at' => ['nullable', 'date', 'after_or_equal:warranty.starts_at'], 'warranty.mileage_limit' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'], 'items.*.service_id' => ['nullable', 'integer', 'exists:automotive_services,id'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'], 'items.*.description' => ['required', 'string'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'], 'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'], 'items.*.requires_approval' => ['boolean'],
            'technician_ids' => ['nullable', 'array'], 'technician_ids.*' => ['integer', 'exists:employees,id'],
        ]);
        $technicianIds = array_values(array_unique($data['technician_ids'] ?? []));
        if ($technicianIds && Employee::whereIn('id', $technicianIds)->whereHas('role', fn ($query) => $query->whereRaw('LOWER(name) = ?', ['technician']))->count() !== count($technicianIds)) {
            abort(422, 'يمكن إسناد أمر الخدمة إلى موظفي Role الفني فقط.');
        }

        $order = DB::transaction(function () use ($data) {
            $items = $data['items']; $technicians = $data['technician_ids'] ?? []; $warranty = $data['warranty'] ?? null;
            unset($data['items'], $data['technician_ids'], $data['warranty']);
            $data['order_number'] = 'AUTO-' . now()->format('YmdHis') . '-' . random_int(100, 999);
            $data['status'] = 'checked_in';
            $data['subtotal'] = collect($items)->sum(fn ($item) => ((float) $item['quantity'] * (float) $item['unit_price']) - (float) ($item['discount_amount'] ?? 0));
            $data['total_amount'] = $data['subtotal'];
            $order = AutomotiveServiceOrder::create($data);
            foreach ($items as $item) {
                $svc = !empty($item['service_id']) ? AutomotiveService::find($item['service_id']) : null;
                // تكلفة المتر الافتراضية للمنتجات لو لم تُرسل
                if ($svc && !isset($item['unit_cost'])) $item['unit_cost'] = (float) $svc->estimated_cost;
                $order->items()->create($item);
                // منتج بالمتر: الكمية = عدد الأمتار، وتُخصم من المخزن (422 لو لا تكفي)
                if ($svc && $svc->item_type === 'product') {
                    $this->stock()->consume($svc, (float) $item['quantity'], [
                        'reference_type' => 'service_order',
                        'reference_id' => $order->id,
                        'note' => 'أمر خدمة ' . $order->order_number,
                    ]);
                }
            }
            foreach (array_values(array_unique($technicians)) as $index => $employeeId) {
                $order->technicians()->attach($employeeId, [
                    'tenant_id' => $order->tenant_id,
                    'is_primary' => $index === 0,
                    'assigned_at' => now(),
                ]);
            }
            if ($warranty) $order->warranty()->create(array_merge($warranty, ['customer_id' => $order->customer_id, 'vehicle_id' => $order->vehicle_id]));
            return $order;
        });

        return response()->json(['status' => true, 'data' => $order->load(['customer', 'vehicle', 'items', 'technicians'])], 201);
    }

    public function showOrder(AutomotiveServiceOrder $order)
    {
        return response()->json(['status' => true, 'data' => $order->load(['customer', 'vehicle', 'branch', 'advisor', 'items.service', 'items.product', 'technicians', 'media'])]);
    }

    public function updateOrderStatus(Request $request, AutomotiveServiceOrder $order)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['draft', 'checked_in', 'diagnosis', 'awaiting_approval', 'approved', 'in_progress', 'quality_check', 'ready_for_delivery', 'delivered', 'on_hold', 'cancelled', 'reopened_under_warranty'])], 'internal_notes' => ['nullable', 'string']]);
        DB::transaction(function () use ($order, $data) {
            $previous = $order->status;
            $order->update($data);
            if ($data['status'] === 'cancelled' && $previous !== 'cancelled') {
                $this->stock()->restoreReference('service_order', $order->id, ['note' => 'إلغاء أمر ' . $order->order_number]);
            }
        });
        return response()->json(['status' => true, 'data' => $order->fresh()->load(['customer', 'vehicle', 'technicians'])]);
    }

    public function updateItemStatus(Request $request, AutomotiveServiceOrder $order, AutomotiveServiceOrderItem $item)
    {
        abort_unless((int) $item->service_order_id === (int) $order->id, 404);
        $data = $request->validate(['status' => ['required', Rule::in(['pending', 'in_progress', 'done', 'cancelled'])]]);
        $item->update($data);
        return response()->json(['status' => true, 'data' => $item->fresh()->load('service')]);
    }

    public function technicianPerformanceReport(Request $request)
    {
        $user = $request->user();
        abort_unless($user instanceof Admin || (bool) ($user?->super_admin ?? false) || strtolower((string) $user?->role?->name) === 'admin', 403, 'Admin access required.');
        $orders = AutomotiveServiceOrder::with(['vehicle', 'items.service', 'technicians'])->whereNotIn('status', ['cancelled'])->get();
        $rows = Employee::query()->whereHas('role', fn ($q) => $q->whereRaw('LOWER(name) = ?', ['technician']))->get()->map(function (Employee $technician) use ($orders): array {
            $techOrders = $orders->filter(fn ($order) => $order->technicians->contains('id', $technician->id));
            return ['technician_id' => $technician->id, 'technician' => $technician->name, 'orders' => $techOrders->count(), 'vehicles' => $techOrders->map(fn ($o) => ['id' => $o->vehicle_id, 'name' => trim(($o->vehicle?->make ?? '') . ' ' . ($o->vehicle?->model ?? ''))])->unique('id')->values(), 'services' => $techOrders->flatMap->items->map(fn ($i) => ['id' => $i->service_id, 'name' => $i->service?->name ?? $i->description, 'status' => $i->status])->values(), 'done_services' => $techOrders->flatMap->items->where('status', 'done')->count(), 'revenue' => round($techOrders->sum('total_amount'), 2)];
        })->values();
        return response()->json(['status' => true, 'data' => $rows]);
    }

    public function assignTechnicians(Request $request, AutomotiveServiceOrder $order)
    {
        $data = $request->validate(['technician_ids' => ['required', 'array', 'min:1'], 'technician_ids.*' => ['integer', 'exists:employees,id']]);
        if (Employee::whereIn('id', $data['technician_ids'])->whereHas('role', fn ($query) => $query->whereRaw('LOWER(name) = ?', ['technician']))->count() !== count(array_unique($data['technician_ids']))) {
            abort(422, 'يمكن إسناد أمر الخدمة إلى موظفي Role الفني فقط.');
        }
        $order->technicians()->sync(collect($data['technician_ids'])->values()->mapWithKeys(fn ($id, $index) => [$id => [
            'tenant_id' => $order->tenant_id,
            'is_primary' => $index === 0,
            'assigned_at' => now(),
        ]])->all());
        return response()->json(['status' => true, 'data' => $order->fresh()->load('technicians')]);
    }

    public function profitabilityReport(Request $request)
    {
        $from = $request->date('from')?->startOfDay() ?? now()->subDays(30)->startOfDay();
        $to = $request->date('to')?->endOfDay() ?? now()->endOfDay();
        $orders = AutomotiveServiceOrder::with(['customer', 'vehicle', 'items.service', 'technicians'])
            ->whereBetween('created_at', [$from, $to])->whereNotIn('status', ['cancelled'])->get();
        $serviceRows = $orders->flatMap(fn ($order) => $order->items)->groupBy('service_id')->map(function ($items, $serviceId) {
            $revenue = $items->sum(fn ($i) => (float) $i->quantity * (float) $i->unit_price - (float) $i->discount_amount);
            $cost = $items->sum(fn ($i) => (float) $i->quantity * (float) $i->unit_cost);
            return ['service_id' => $serviceId, 'service' => $items->first()->service?->name ?? 'قطع غيار / أخرى', 'orders' => $items->pluck('service_order_id')->unique()->count(), 'revenue' => round($revenue, 2), 'cost' => round($cost, 2), 'profit' => round($revenue - $cost, 2)];
        })->values();
        $technicianRows = $orders->flatMap(function ($order) {
            $count = max(1, $order->technicians->count());
            $revenue = (float) $order->total_amount / $count;
            $cost = (float) $order->items->sum(fn ($i) => (float) $i->quantity * (float) $i->unit_cost) / $count;
            return $order->technicians->map(fn ($tech) => ['technician_id' => $tech->id, 'technician' => $tech->name, 'orders' => 1, 'revenue' => $revenue, 'cost' => $cost, 'profit' => $revenue - $cost]);
        })->groupBy('technician_id')->map(fn ($rows) => ['technician_id' => $rows->first()['technician_id'], 'technician' => $rows->first()['technician'], 'orders' => $rows->sum('orders'), 'revenue' => round($rows->sum('revenue'), 2), 'cost' => round($rows->sum('cost'), 2), 'profit' => round($rows->sum('profit'), 2)])->values();
        $customerRows = $orders->groupBy('customer_id')->map(function ($customerOrders) {
            $revenue = $customerOrders->sum('total_amount');
            $cost = $customerOrders->sum(fn ($o) => $o->items->sum(fn ($i) => (float) $i->quantity * (float) $i->unit_cost));
            return ['customer_id' => $customerOrders->first()->customer_id, 'customer' => $customerOrders->first()->customer?->name ?? 'عميل غير معروف', 'orders' => $customerOrders->count(), 'revenue' => round($revenue, 2), 'cost' => round($cost, 2), 'profit' => round($revenue - $cost, 2)];
        })->values();
        $totalCost = $orders->sum(fn ($o) => $o->items->sum(fn ($i) => (float) $i->quantity * (float) $i->unit_cost));
        $totalRevenue = $orders->sum('total_amount');
        $details = $orders->flatMap(fn ($order) => $order->items->map(fn ($item) => [
            'order_id' => $order->id, 'order_number' => $order->order_number,
            'customer' => $order->customer?->name ?? 'عميل غير معروف',
            'vehicle' => trim(($order->vehicle?->make ?? '') . ' ' . ($order->vehicle?->model ?? '')),
            'service' => $item->service?->name ?? $item->description, 'quantity' => (float) $item->quantity,
            'meter_quantity' => $item->service?->item_type === 'product' ? 1 : 0,
            'unit_price' => (float) $item->unit_price, 'unit_cost' => (float) $item->unit_cost,
            'revenue' => round((float) $item->quantity * (float) $item->unit_price, 2),
            'profit' => round((float) $item->quantity * ((float) $item->unit_price - (float) $item->unit_cost), 2),
            'created_at' => $order->created_at?->toDateString(),
        ]))->values();

        // ===== فواتير POS (خدمات / منتجات السيارات) =====
        $posInvoices = Invoice::with(['items.product', 'items.automotiveService', 'customer'])
            ->whereBetween('created_at', [$from, $to])
            ->whereHas('items', fn ($query) => $query->where('item_type', 'service'))
            ->get();

        // تكلفة بند POS: منتج بالمتر => تكلفة المتر × عدد السيارات × أمتار كل سيارة، وخدمة عادية => التكلفة × الكمية
        $posItemCost = function ($item): float {
            $service = $item->automotiveService;
            if (!$service) return 0.0;
            $meters = $service->item_type === 'product' ? (float) ($item->meter_quantity ?: 1) : 1.0;
            return (float) ($service->estimated_cost ?? 0) * (float) $item->quantity * $meters;
        };

        $posServiceItems = $posInvoices->flatMap->items->filter(fn ($item) => $item->item_type === 'service');

        $posServiceRows = $posServiceItems
            ->groupBy(fn ($item) => $item->automotive_service_id ?? "product-{$item->product_id}")
            ->map(function ($items, $key) use ($posItemCost) {
                $first = $items->first();
                $service = $first->automotiveService;
                $revenue = $items->sum(fn ($item) => (float) $item->total);
                $cost = $items->sum(fn ($item) => $posItemCost($item));
                $metersSold = $items->sum(fn ($item) => (float) $item->quantity * (float) ($item->meter_quantity ?? 1));

                $bySize = $items->groupBy('vehicle_size')->map(fn ($group) => [
                    'count' => $group->count(),
                    'meters' => round($group->sum(fn ($i) => (float) $i->quantity * (float) ($i->meter_quantity ?? 1)), 3),
                    'revenue' => round($group->sum('total'), 2),
                ]);

                return [
                    'service_id' => $service?->id,
                    'product_id' => $service?->product_id,
                    'service' => $service?->name ?? $first->product_name,
                    'orders' => $items->pluck('invoice_id')->unique()->count(),
                    'revenue' => round($revenue, 2),
                    'cost' => round($cost, 2),
                    'profit' => round($revenue - $cost, 2),
                    'meters_sold' => round($metersSold, 3),
                    'stock_remaining' => (float) ($service?->stock_quantity ?? $first->product?->stock ?? 0),
                    'by_vehicle_size' => $bySize,
                ];
            })->values();

        $serviceRows = $serviceRows->concat($posServiceRows)->values();

        $posDetails = $posServiceItems->map(function ($item) use ($posItemCost) {
            $invoice = $item->invoice;
            $quantity = (float) $item->quantity;
            $cost = $posItemCost($item);
            return [
                'order_id' => $item->invoice_id,
                'order_number' => $invoice?->invoice_number,
                'customer' => $invoice?->customer?->name ?? 'عميل POS',
                'vehicle' => null,
                'service' => $item->product_name,
                'quantity' => $quantity,
                'meter_quantity' => (float) ($item->meter_quantity ?? 0),
                'unit_price' => (float) $item->price,
                'unit_cost' => $quantity > 0 ? round($cost / $quantity, 2) : 0,
                'revenue' => (float) $item->total,
                'profit' => round((float) $item->total - $cost, 2),
                'stock_remaining' => (float) ($item->automotiveService?->stock_quantity ?? $item->product?->stock ?? 0),
                'created_at' => $invoice?->created_at?->toDateString(),
            ];
        })->values();
        $details = $details->concat($posDetails)->values();

        $totalRevenue += $posServiceRows->sum('revenue');
        $totalCost += $posServiceRows->sum('cost');

        // ===== مخزون المنتجات بالمتر: الرصيد، المشتريات، المباع، القيمة =====
        $purchasedByService = AutomotiveStockMovement::query()
            ->whereBetween('created_at', [$from, $to])->where('type', 'purchase')
            ->get()->groupBy('service_id');
        $orderMeters = $orders->flatMap(fn ($order) => $order->items)->filter(fn ($i) => $i->service_id)
            ->groupBy('service_id')->map(fn ($group) => (float) $group->sum('quantity'));
        $posMeters = $posServiceRows->filter(fn ($row) => $row['service_id'])->keyBy('service_id')
            ->map(fn ($row) => (float) $row['meters_sold']);

        $inventory = AutomotiveService::query()->where('item_type', 'product')->orderBy('name')->get()
            ->map(function ($service) use ($purchasedByService, $orderMeters, $posMeters) {
                $purchases = $purchasedByService->get($service->id, collect());
                $stock = (float) $service->stock_quantity;
                $costPerMeter = (float) $service->estimated_cost;
                return [
                    'service_id' => $service->id,
                    'service' => $service->name,
                    'unit' => AutomotiveStockService::UNIT,
                    'stock_meters' => round($stock, 3),
                    'cost_per_meter' => round($costPerMeter, 2),
                    'stock_value' => round($stock * $costPerMeter, 2),
                    'purchased_meters' => round((float) $purchases->sum('meters'), 3),
                    'purchase_value' => round((float) $purchases->sum(fn ($m) => (float) $m->meters * (float) $m->unit_cost), 2),
                    'sold_meters' => round((float) $orderMeters->get($service->id, 0) + (float) $posMeters->get($service->id, 0), 3),
                ];
            })->values();

        return response()->json(['status' => true, 'data' => [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'summary' => [
                'orders' => $orders->count(),
                'revenue' => round($totalRevenue, 2),
                'cost' => round($totalCost, 2),
                'profit' => round($totalRevenue - $totalCost, 2),
                'purchases' => round($inventory->sum('purchase_value'), 2),
                'inventory_value' => round($inventory->sum('stock_value'), 2),
            ],
            'services' => $serviceRows,
            'technicians' => $technicianRows,
            'customers' => $customerRows,
            'details' => $details,
            'inventory' => $inventory,
        ]]);
    }
}