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
        $account = AutomotiveCustomerAccount::create([
            'customer_id' => $data['customer_id'], 'email' => $data['email'], 'password' => Hash::make($data['password']), 'active' => $data['active'] ?? true,
        ]);
        return response()->json(['status' => true, 'data' => $account->load('customer')], 201);
    }

    public function customerDashboard(Request $request)
    {
        $account = $request->user();
        abort_unless($account instanceof AutomotiveCustomerAccount, 403, 'Customer portal authentication required.');
        $customer = $account->customer;
        return response()->json([
            'status' => true,
            'data' => [
                'customer' => $customer,
                'vehicles' => $customer->automotiveVehicles()->withCount('serviceOrders')->get(),
                'orders' => $customer->automotiveServiceOrders()->with(['vehicle', 'technicians', 'items', 'media'])->latest()->get(),
                'warranties' => AutomotiveWarranty::where('customer_id', $customer->id)->with('vehicle')->latest()->get(),
                'visits' => AutomotiveVisit::where('customer_id', $customer->id)->with(['vehicle', 'serviceOrder'])->latest()->get(),
                'invoices' => $customer->salesInvoices()->latest()->get()->map(fn ($invoice) => [
                    'id' => $invoice->id, 'number' => $invoice->invoice_number, 'total' => (float) ($invoice->net_total ?? $invoice->total_amount ?? 0),
                    'paid' => (float) ($invoice->paid_amount ?? 0), 'status' => $invoice->payment_status ?? 'unpaid', 'date' => $invoice->created_at?->toDateString(),
                ]),
            ],
        ]);
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
