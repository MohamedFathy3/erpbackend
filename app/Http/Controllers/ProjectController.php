<?php
namespace App\Http\Controllers;
use App\Models\Project;
use Illuminate\Http\Request;
class ProjectController extends Controller
{
    public function dashboard(Request $request)
    {
        $projects = Project::with(['customer', 'wbsItems', 'claims'])->latest()->paginate($request->integer('per_page', 20));
        return response()->json(['status' => true, 'data' => ['projects' => $projects, 'active_count' => Project::where('status', 'active')->count(), 'contract_value' => (float) Project::whereIn('status', ['active', 'completed'])->sum('contract_value'), 'budget_cost' => (float) Project::sum('budget_cost'), 'actual_cost' => (float) Project::sum('actual_cost'), 'pending_claims' => Project::whereHas('claims', fn ($q) => $q->whereIn('status', ['submitted', 'approved']))->count()]]);
    }
    public function index(Request $request) { return response()->json(['status' => true, 'data' => Project::with('customer')->latest()->paginate($request->integer('per_page', 20))]); }
    public function show(Project $project) { return response()->json(['status' => true, 'data' => $project->load(['customer', 'wbsItems.children', 'claims'])]); }
}
