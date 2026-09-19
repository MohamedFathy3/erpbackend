<?php

namespace App\Http\Controllers;

use App\Models\AutomotiveService;
use App\Models\AutomotiveServiceOrder;
use App\Models\AutomotiveVehicle;
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
        $query = AutomotiveService::query()->latest();
        if ($request->has('active')) $query->where('active', $request->boolean('active'));
        if ($request->filled('search')) $query->where(fn ($q) => $q->where('name', 'like', '%' . $request->string('search') . '%')->orWhere('code', 'like', '%' . $request->string('search') . '%'));
        return response()->json(['status' => true, 'data' => $query->paginate($request->integer('per_page', 25))]);
    }

    public function storeService(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50'], 'name' => ['required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string'],
            'selling_price' => ['required', 'numeric', 'min:0'], 'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'estimated_minutes' => ['nullable', 'integer', 'min:1'], 'warranty_eligible' => ['boolean'], 'active' => ['boolean'],
        ]);
        $service = AutomotiveService::create($data);
        return response()->json(['status' => true, 'data' => $service], 201);
    }

    public function updateService(Request $request, AutomotiveService $service)
    {
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:50'], 'name' => ['sometimes', 'string', 'max:255'], 'name_ar' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'], 'selling_price' => ['sometimes', 'numeric', 'min:0'], 'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'estimated_minutes' => ['nullable', 'integer', 'min:1'], 'warranty_eligible' => ['boolean'], 'active' => ['boolean'],
        ]);
        $service->update($data);
        return response()->json(['status' => true, 'data' => $service->fresh()]);
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
            $items = $data['items']; $technicians = $data['technician_ids'] ?? [];
            unset($data['items'], $data['technician_ids']);
            $data['order_number'] = 'AUTO-' . now()->format('YmdHis') . '-' . random_int(100, 999);
            $data['status'] = 'checked_in';
            $data['subtotal'] = collect($items)->sum(fn ($item) => ((float) $item['quantity'] * (float) $item['unit_price']) - (float) ($item['discount_amount'] ?? 0));
            $data['total_amount'] = $data['subtotal'];
            $order = AutomotiveServiceOrder::create($data);
            foreach ($items as $item) $order->items()->create($item);
            foreach (array_values(array_unique($technicians)) as $index => $employeeId) {
                $order->technicians()->attach($employeeId, [
                    'tenant_id' => $order->tenant_id,
                    'is_primary' => $index === 0,
                    'assigned_at' => now(),
                ]);
            }
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
        $order->update($data);
        return response()->json(['status' => true, 'data' => $order->fresh()->load(['customer', 'vehicle', 'technicians'])]);
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
        $orders = AutomotiveServiceOrder::with(['customer', 'items.service', 'technicians'])
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
        return response()->json(['status' => true, 'data' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'summary' => ['orders' => $orders->count(), 'revenue' => round($totalRevenue, 2), 'cost' => round($totalCost, 2), 'profit' => round($totalRevenue - $totalCost, 2)], 'services' => $serviceRows, 'technicians' => $technicianRows, 'customers' => $customerRows]]);
    }
}
