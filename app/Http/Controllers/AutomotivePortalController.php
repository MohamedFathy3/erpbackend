<?php

namespace App\Http\Controllers;

use App\Models\AutomotiveCustomerAccount;
use App\Models\AutomotiveServiceOrder;
use App\Models\AutomotiveVisit;
use App\Models\AutomotiveWarranty;
use App\Models\Employee;
use App\Models\Media;
use App\Models\ProductWarehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AutomotivePortalController extends BaseController
{
    public function customerLogin(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $account = AutomotiveCustomerAccount::where('email', $data['email'])->first();
        if (!$account || !$account->active || !Hash::check($data['password'], $account->password)) return response()->json(['message' => 'Invalid customer credentials'], 401);
        $account->update(['last_login_at' => now()]);
        return response()->json(['type' => 'customer', 'token' => $account->createToken('customer-portal')->plainTextToken, 'data' => $account->load('customer')]);
    }

    public function createCustomerAccount(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'email' => ['required', 'email'], 'password' => ['required', 'string', 'min:8'], 'active' => ['boolean'],
        ]);
        $account = AutomotiveCustomerAccount::withTrashed()->where('customer_id', $data['customer_id'])->first();
        if ($account?->trashed()) $account->restore();
        if (!$account) $account = new AutomotiveCustomerAccount(['customer_id' => $data['customer_id']]);
        $account->email = $data['email'];
        $account->password = Hash::make($data['password']);
        $account->active = $data['active'] ?? true;
        $account->save();
        return response()->json(['status' => true, 'data' => $account->load('customer')], 201);
    }

    public function customerDashboard(Request $request)
    {
        $account = $request->user();
        abort_unless($account instanceof AutomotiveCustomerAccount, 403, 'Customer portal authentication required.');
        $customer = $account->customer;
        $serviceOrders = $customer->automotiveServiceOrders()->with('items.service')->latest()->get();
        return response()->json([
            'status' => true,
            'data' => [
                'customer' => $customer,
                'vehicles' => $customer->automotiveVehicles()->withCount('serviceOrders')->get(),
                'orders' => $serviceOrders->load(['vehicle', 'technicians', 'items', 'media']),
                'warranties' => AutomotiveWarranty::where('customer_id', $customer->id)->with('vehicle')->latest()->get(),
                'visits' => AutomotiveVisit::where('customer_id', $customer->id)->with(['vehicle', 'serviceOrder'])->latest()->get(),
                'invoices' => $customer->salesInvoices()->latest()->get()->map(fn ($invoice) => [
                    'id' => $invoice->id, 'number' => $invoice->invoice_number, 'total' => (float) ($invoice->net_total ?? $invoice->total_amount ?? 0),
                    'paid' => (float) ($invoice->paid_amount ?? 0), 'status' => $invoice->payment_status ?? 'unpaid', 'date' => $invoice->created_at?->toDateString(),
                ])->concat($serviceOrders->map(fn ($order) => [
                    'id' => 'service-' . $order->id, 'number' => $order->order_number, 'total' => (float) $order->total_amount,
                    'paid' => 0, 'status' => 'service_' . $order->status, 'date' => $order->created_at?->toDateString(),
                ]))->values(),
            ],
        ]);
    }

    public function requestVisit(Request $request)
    {
        $account = $request->user();
        abort_unless($account instanceof AutomotiveCustomerAccount, 403, 'Customer portal authentication required.');
        $customer = $account->customer;
        $data = $request->validate([
            'vehicle_id' => ['required', 'integer', 'exists:automotive_vehicles,id'],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'type' => ['required', 'string', 'max:40'],
            'purpose' => ['nullable', 'string', 'max:2000'],
        ]);
        $vehicle = $customer->automotiveVehicles()->whereKey($data['vehicle_id'])->firstOrFail();
        $visit = AutomotiveVisit::create([
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'type' => $data['type'],
            'status' => 'requested',
            'scheduled_at' => $data['scheduled_at'],
            'purpose' => $data['purpose'] ?? null,
        ]);
        return response()->json(['status' => true, 'data' => $visit->load(['vehicle', 'serviceOrder'])], 201);
    }

    public function technicianLogin(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $employee = Employee::where('email', $data['email'])->first();
        if (!$employee || !Hash::check($data['password'], $employee->password) || !$employee->hasPermission('automotive.portal.technician_login')) return response()->json(['message' => 'Invalid technician credentials or portal access is not enabled'], 401);
        return response()->json(['type' => 'technician', 'token' => $employee->createToken('technician-portal')->plainTextToken, 'data' => $employee->load('role')]);
    }

    public function technicianOrders(Request $request)
    {
        $employee = $this->technician($request);
        $orders = $employee->automotiveAssignments()->with(['order.customer', 'order.vehicle', 'order.items', 'order.media'])->latest()->get()->pluck('order')->filter()->values();
        return response()->json(['status' => true, 'data' => $orders]);
    }

    public function technicianVisits(Request $request)
    {
        $employee = $this->technician($request);
        $vehicleIds = $employee->automotiveAssignments()->with('order')->get()->pluck('order.vehicle_id')->filter()->unique();
        return response()->json(['status' => true, 'data' => AutomotiveVisit::whereIn('vehicle_id', $vehicleIds)->with(['customer', 'vehicle', 'serviceOrder'])->latest()->get()]);
    }

    public function technicianUpdateVisit(Request $request, AutomotiveVisit $visit)
    {
        $employee = $this->technician($request);
        abort_unless($employee->automotiveAssignments()->whereHas('order', fn ($q) => $q->where('vehicle_id', $visit->vehicle_id))->exists(), 403, 'This visit is not assigned to you.');
        $data = $request->validate(['status' => ['required', Rule::in(['scheduled', 'accepted', 'reschedule_requested', 'checked_in', 'completed', 'cancelled'])], 'scheduled_at' => ['nullable', 'date', 'after:now'], 'advisor_id' => ['nullable', 'integer', 'exists:employees,id']]);
        $visit->update(array_filter($data, fn ($value) => $value !== null));
        return response()->json(['status' => true, 'data' => $visit->fresh()->load(['customer', 'vehicle', 'serviceOrder'])]);
    }

    public function technicianUpdateStatus(Request $request, AutomotiveServiceOrder $order)
    {
        $employee = $this->technician($request);
        abort_unless($order->technicians()->whereKey($employee->id)->exists(), 403, 'This service order is not assigned to you.');
        $data = $request->validate(['status' => ['required', Rule::in(['diagnosis', 'in_progress', 'quality_check', 'ready_for_delivery'])], 'internal_notes' => ['nullable', 'string']]);
        DB::transaction(function () use ($order, $data) {
            if ($data['status'] === 'in_progress' && !$order->inventory_consumed_at) {
                foreach ($order->items()->whereNotNull('product_id')->get() as $item) {
                    $stock = ProductWarehouse::where('product_id', $item->product_id)
                        ->when($order->warehouse_id, fn ($q) => $q->where('warehouse_id', $order->warehouse_id))
                        ->lockForUpdate()->first();
                    abort_unless($stock && (float) $stock->stock >= (float) $item->quantity, 422, 'Insufficient inventory for service parts.');
                    $stock->decrement('stock', $item->quantity);
                }
                $data['inventory_consumed_at'] = now();
            }
            $order->update($data);
        });
        return response()->json(['status' => true, 'data' => $order->fresh()->load(['customer', 'vehicle', 'items', 'technicians'])]);
    }

    public function technicianUpdateItemStatus(Request $request, AutomotiveServiceOrder $order, \App\Models\AutomotiveServiceOrderItem $item)
    {
        $employee = $this->technician($request);
        abort_unless((int) $item->service_order_id === (int) $order->id && $order->technicians()->whereKey($employee->id)->exists(), 403, 'This service is not assigned to you.');
        $data = $request->validate(['status' => ['required', Rule::in(['pending', 'in_progress', 'done', 'cancelled'])]]);
        $item->update($data);
        return response()->json(['status' => true, 'data' => $order->fresh()->load(['customer', 'vehicle', 'items.service', 'technicians'])]);
    }

    public function technicianCreateWarranty(Request $request, AutomotiveServiceOrder $order)
    {
        $employee = $this->technician($request);
        abort_unless($order->technicians()->whereKey($employee->id)->exists(), 403, 'This service order is not assigned to you.');
        $data = $request->validate([
            'policy_name' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'mileage_limit' => ['nullable', 'numeric', 'min:0'],
            'terms' => ['nullable', 'string', 'max:2000'],
        ]);
        $warranty = AutomotiveWarranty::updateOrCreate(
            ['service_order_id' => $order->id, 'tenant_id' => $order->tenant_id],
            array_merge($data, ['customer_id' => $order->customer_id, 'vehicle_id' => $order->vehicle_id, 'status' => 'active'])
        );
        return response()->json(['status' => true, 'data' => $warranty->load('vehicle')], 201);
    }

    public function technicianUploadPhoto(Request $request, AutomotiveServiceOrder $order)
    {
        $employee = $this->technician($request);
        abort_unless($order->technicians()->whereKey($employee->id)->exists(), 403, 'This service order is not assigned to you.');
        $data = $request->validate([
            'photo' => ['required', 'file', 'image', 'max:10240'],
            'stage' => ['required', Rule::in(['before', 'during', 'after'])],
            'customer_visible' => ['boolean'], 'caption' => ['nullable', 'string', 'max:500'],
        ]);
        $file = $data['photo'];
        $fileName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $storedName = Str::slug($fileName) . '-' . Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('media/automotive', $storedName, 'public');
        $media = Media::create(['name' => $fileName, 'file_name' => $storedName, 'mime_type' => $file->getMimeType(), 'size' => $file->getSize(), 'author_id' => $employee->id, 'file_path' => $path]);
        $order->media()->attach($media->id, ['collection' => 'automotive_' . $data['stage'], 'customer_visible' => (bool) ($data['customer_visible'] ?? false)]);
        return response()->json(['status' => true, 'data' => $media->fresh()]);
    }

    private function technician(Request $request): Employee
    {
        $employee = $request->user();
        abort_unless($employee instanceof Employee && $employee->hasPermission('automotive.portal.technician_login'), 403, 'Technician portal authentication required.');
        return $employee;
    }
}
