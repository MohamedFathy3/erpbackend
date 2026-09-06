<?php
namespace App\Http\Controllers;

use App\Models\WorkflowTransaction;
use Illuminate\Http\Request;

class WorkflowController extends Controller
{
    public function index(Request $request)
    {
        $query = WorkflowTransaction::with(['journalEntry', 'inventoryMovement.product'])->latest('occurred_at');
        if ($request->filled('event')) $query->where('event', $request->string('event'));
        if ($request->filled('status')) $query->where('status', $request->string('status'));
        return response()->json(['status' => true, 'data' => $query->paginate($request->integer('per_page', 30)), 'summary' => [
            'total' => WorkflowTransaction::count(),
            'completed' => WorkflowTransaction::where('status', 'completed')->count(),
            'failed' => WorkflowTransaction::where('status', 'failed')->count(),
            'today' => WorkflowTransaction::whereDate('occurred_at', now()->toDateString())->count(),
        ]]);
    }
}
