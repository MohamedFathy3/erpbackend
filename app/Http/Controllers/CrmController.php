<?php
namespace App\Http\Controllers;

use App\Http\Requests\CrmActivityRequest;
use App\Http\Requests\DealRequest;
use App\Http\Requests\LeadRequest;
use App\Http\Requests\PipelineStageRequest;
use App\Models\CrmActivity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\PipelineStage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CrmController extends Controller
{
    private function ensureTenantRecord($model): void { abort_unless($model, 404); }

    public function dashboard()
    {
        $stages = PipelineStage::withCount('deals')->orderBy('sort_order')->get();
        return response()->json(['data' => [
            'stages' => $stages,
            'deals_count' => Deal::count(),
            'pipeline_value' => (float) Deal::sum('value'),
            'won_count' => Deal::whereHas('stage', fn($q) => $q->where('is_won', true))->count(),
            'conversion_rate' => Lead::count() ? round((Lead::where('status', 'converted')->count() / Lead::count()) * 100, 2) : 0,
        ]]);
    }

    public function stages() { return response()->json(['data' => PipelineStage::orderBy('sort_order')->get()]); }
    public function storeStage(PipelineStageRequest $request) { $stage = PipelineStage::create($request->validated()); return response()->json(['data'=>$stage], 201); }
    public function updateStage(PipelineStageRequest $request, PipelineStage $pipelineStage) { $pipelineStage->update($request->validated()); return response()->json(['data'=>$pipelineStage]); }
    public function destroyStage(PipelineStage $pipelineStage) { abort_if($pipelineStage->deals()->exists(), 422, 'Cannot delete a stage containing deals.'); $pipelineStage->delete(); return response()->json(['message'=>'deleted']); }

    public function leads(Request $request) { return response()->json(['data'=>Lead::with(['customer','assignee'])->latest()->paginate($request->integer('per_page', 25))]); }
    public function storeLead(LeadRequest $request) { $data=$request->validated(); if (!empty($data['customer_id'])) $this->ensureTenantRecord(Customer::find($data['customer_id'])); $lead=Lead::create($data); return response()->json(['data'=>$lead->load(['customer','assignee'])], 201); }
    public function showLead(Lead $lead) { return response()->json(['data'=>$lead->load(['customer','assignee','deals.stage','activities'])]); }
    public function updateLead(LeadRequest $request, Lead $lead) { $data=$request->validated(); if (!empty($data['customer_id'])) $this->ensureTenantRecord(Customer::find($data['customer_id'])); $lead->update($data); return response()->json(['data'=>$lead->fresh()->load(['customer','assignee'])]); }
    public function destroyLead(Lead $lead) { $lead->delete(); return response()->json(['message'=>'deleted']); }

    public function deals(Request $request) { return response()->json(['data'=>Deal::with(['stage','lead','customer','assignee'])->latest()->paginate($request->integer('per_page', 50))]); }
    public function storeDeal(DealRequest $request) { $data=$request->validated(); $this->validateDealRelations($data); $deal=Deal::create($data); return response()->json(['data'=>$deal->load(['stage','lead','customer','assignee'])], 201); }
    public function showDeal(Deal $deal) { return response()->json(['data'=>$deal->load(['stage','lead','customer','assignee','activities'])]); }
    public function updateDeal(DealRequest $request, Deal $deal) { $data=$request->validated(); $this->validateDealRelations($data); $deal->update($data); return response()->json(['data'=>$deal->fresh()->load(['stage','lead','customer','assignee'])]); }
    public function destroyDeal(Deal $deal) { $deal->delete(); return response()->json(['message'=>'deleted']); }
    public function moveStage(Request $request, Deal $deal) { $data=$request->validate(['stage_id'=>'required|integer']); $this->ensureTenantRecord(PipelineStage::find($data['stage_id'])); $deal->update(['stage_id'=>$data['stage_id']]); return response()->json(['data'=>$deal->fresh()->load('stage')]); }

    public function activities(Request $request) { $query=CrmActivity::with(['creator','lead','deal','customer'])->latest('occurred_at'); foreach (['lead_id','deal_id','customer_id','type'] as $field) if ($request->filled($field)) $query->where($field,$request->input($field)); return response()->json(['data'=>$query->paginate($request->integer('per_page', 50))]); }
    public function storeActivity(CrmActivityRequest $request) { $data=$request->validated(); $data['created_by']=$request->user()->id; $activity=CrmActivity::create($data); return response()->json(['data'=>$activity->load(['creator','lead','deal','customer'])], 201); }
    public function showActivity(CrmActivity $activity) { return response()->json(['data'=>$activity->load(['creator','lead','deal','customer'])]); }
    public function updateActivity(CrmActivityRequest $request, CrmActivity $activity) { $activity->update($request->validated()); return response()->json(['data'=>$activity->fresh()]); }
    public function destroyActivity(CrmActivity $activity) { $activity->delete(); return response()->json(['message'=>'deleted']); }

    private function validateDealRelations(array $data): void { $this->ensureTenantRecord(PipelineStage::find($data['stage_id'])); if (!empty($data['lead_id'])) $this->ensureTenantRecord(Lead::find($data['lead_id'])); if (!empty($data['customer_id'])) $this->ensureTenantRecord(Customer::find($data['customer_id'])); }
}
