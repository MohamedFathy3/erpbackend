<?php
namespace App\Http\Controllers;
use App\Models\ManufacturingBom;
use App\Models\ManufacturingOrder;
use App\Models\ManufacturingWorkCenter;
use Illuminate\Http\Request;
class ManufacturingController extends Controller
{
    public function dashboard(Request $request)
    {
        $orders = ManufacturingOrder::with(['product', 'bom', 'inspections'])->latest()->paginate($request->integer('per_page', 20));
        return response()->json(['status' => true, 'data' => ['orders' => $orders, 'boms_count' => ManufacturingBom::count(), 'work_centers_count' => ManufacturingWorkCenter::where('active', true)->count(), 'in_progress' => ManufacturingOrder::where('status', 'in_progress')->count(), 'quality_hold' => ManufacturingOrder::where('status', 'quality_hold')->count()]]);
    }
    public function boms() { return response()->json(['status' => true, 'data' => ManufacturingBom::with(['product', 'items.product'])->where('status', 'active')->get()]); }
    public function orders(Request $request) { return response()->json(['status' => true, 'data' => ManufacturingOrder::with(['product', 'bom'])->latest()->paginate($request->integer('per_page', 20))]); }
}
