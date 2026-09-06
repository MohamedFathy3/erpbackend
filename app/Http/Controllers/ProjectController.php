<?php
namespace App\Http\Controllers;

use App\Models\JournalEntry;
use App\Models\Project;
use App\Models\ProjectClaim;
use App\Models\ProjectCostEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function dashboard(Request $request)
    {
        $projects = Project::with(['customer', 'wbsItems', 'claims'])->latest()->paginate($request->integer('per_page', 20));
        return response()->json(['status' => true, 'data' => ['projects' => $projects, 'active_count' => Project::where('status', 'active')->count(), 'contract_value' => (float) Project::whereIn('status', ['active', 'completed'])->sum('contract_value'), 'budget_cost' => (float) Project::sum('budget_cost'), 'actual_cost' => (float) Project::sum('actual_cost'), 'pending_claims' => ProjectClaim::whereIn('status', ['submitted', 'approved'])->count()]]);
    }

    public function index(Request $request) { return response()->json(['status' => true, 'data' => Project::with('customer')->latest()->paginate($request->integer('per_page', 20))]); }
    public function show(Project $project) { return response()->json(['status' => true, 'data' => $project->load(['customer', 'wbsItems.children', 'claims', 'costEntries'])]); }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:200'], 'customer_id' => ['nullable', 'exists:customers,id'], 'contract_number' => ['nullable', 'string', 'max:100'], 'start_date' => ['nullable', 'date'], 'planned_end_date' => ['nullable', 'date', 'after_or_equal:start_date'], 'status' => ['nullable', Rule::in(['draft', 'active', 'on_hold', 'completed', 'cancelled'])], 'contract_value' => ['nullable', 'numeric', 'min:0'], 'budget_cost' => ['nullable', 'numeric', 'min:0'], 'retention_percent' => ['nullable', 'numeric', 'min:0', 'max:100'], 'scope' => ['nullable', 'string']]);
        $data['project_code'] = 'PRJ-' . now()->format('YmdHis') . '-' . random_int(100, 999);
        return response()->json(['status' => true, 'data' => Project::create($data)], 201);
    }

    public function update(Request $request, Project $project)
    {
        abort_if(in_array($project->status, ['completed', 'cancelled']), 422, 'لا يمكن تعديل مشروع مغلق');
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:200'], 'customer_id' => ['nullable', 'exists:customers,id'], 'contract_number' => ['nullable', 'string', 'max:100'], 'start_date' => ['nullable', 'date'], 'planned_end_date' => ['nullable', 'date', 'after_or_equal:start_date'], 'status' => ['nullable', Rule::in(['draft', 'active', 'on_hold', 'completed', 'cancelled'])], 'contract_value' => ['nullable', 'numeric', 'min:0'], 'budget_cost' => ['nullable', 'numeric', 'min:0'], 'retention_percent' => ['nullable', 'numeric', 'min:0', 'max:100'], 'scope' => ['nullable', 'string']]);
        $project->update($data);
        return response()->json(['status' => true, 'data' => $project->fresh('customer')]);
    }

    public function destroy(Project $project)
    {
        abort_if($project->claims()->exists() || $project->costEntries()->exists(), 422, 'لا يمكن حذف مشروع له مستخلصات أو تكاليف؛ غيّر حالته إلى ملغى');
        $project->update(['status' => 'cancelled']);
        return response()->json(['status' => true, 'message' => 'تم إلغاء المشروع مع الاحتفاظ بالسجل']);
    }

    public function createClaim(Request $request, Project $project)
    {
        $data = $request->validate(['gross_amount' => ['required', 'numeric', 'gt:0'], 'advance_deduction' => ['nullable', 'numeric', 'min:0'], 'retention_amount' => ['nullable', 'numeric', 'min:0'], 'notes' => ['nullable', 'string']]);
        $net = (float) $data['gross_amount'] - (float) ($data['advance_deduction'] ?? 0) - (float) ($data['retention_amount'] ?? 0);
        abort_if($net <= 0, 422, 'صافي المستخلص يجب أن يكون أكبر من صفر');
        $claim = $project->claims()->create(['claim_number' => 'CLM-' . $project->project_code . '-' . now()->format('YmdHis'), 'claim_date' => now()->toDateString(), 'gross_amount' => $data['gross_amount'], 'advance_deduction' => $data['advance_deduction'] ?? 0, 'retention_amount' => $data['retention_amount'] ?? 0, 'net_amount' => $net, 'status' => 'submitted', 'notes' => $data['notes'] ?? null]);
        \App\Models\WorkflowTransaction::capture('project:claim:created:' . $claim->id, $claim, 'claim_submitted', ['net_amount' => $net]);
        return response()->json(['status' => true, 'message' => 'تم إنشاء المستخلص وإرساله للاعتماد', 'data' => $claim], 201);
    }

    public function addCost(Request $request, Project $project)
    {
        $data = $request->validate(['cost_type' => ['required', 'string', 'max:100'], 'amount' => ['required', 'numeric', 'gt:0'], 'debit_account_id' => ['required', 'exists:accounts,id'], 'credit_account_id' => ['required', 'exists:accounts,id'], 'reference_type' => ['nullable', 'string'], 'reference_id' => ['nullable', 'integer'], 'description' => ['nullable', 'string']]);
        $entry = DB::transaction(function () use ($data, $project) {
            $journal = JournalEntry::create(['entry_date' => now()->toDateString(), 'description_ar' => "تكلفة مشروع {$project->project_code}", 'description_en' => "Project cost {$project->project_code}", 'status' => 'posted']);
            $journal->lines()->createMany([['account_id' => $data['debit_account_id'], 'debit' => $data['amount'], 'credit' => 0, 'description' => $data['description'] ?? 'تكلفة مباشرة على المشروع'], ['account_id' => $data['credit_account_id'], 'debit' => 0, 'credit' => $data['amount'], 'description' => 'مصدر تكلفة المشروع']]);
            $entry = ProjectCostEntry::create(['project_id' => $project->id, 'cost_type' => $data['cost_type'], 'amount' => $data['amount'], 'journal_entry_id' => $journal->id, 'reference_type' => $data['reference_type'] ?? null, 'reference_id' => $data['reference_id'] ?? null, 'description' => $data['description'] ?? null]);
            $project->increment('actual_cost', $data['amount']);
            \App\Models\WorkflowTransaction::capture('project:cost:' . $entry->id, $project, 'project_cost_posted', ['amount' => $data['amount'], 'cost_type' => $data['cost_type']], $journal->id);
            return $entry->load('journalEntry');
        });
        return response()->json(['status' => true, 'message' => 'تم تسجيل تكلفة المشروع وترحيل القيد المالي', 'data' => $entry], 201);
    }

    public function approveClaim(Request $request, ProjectClaim $claim)
    {
        $data = $request->validate(['receivable_account_id' => ['required', 'exists:accounts,id'], 'revenue_account_id' => ['required', 'exists:accounts,id']]);
        abort_if($claim->status !== 'submitted', 422, 'المستخلص يجب أن يكون مقدماً قبل الاعتماد');
        $updated = DB::transaction(function () use ($claim, $data) {
            $journal = JournalEntry::create(['entry_date' => now()->toDateString(), 'description_ar' => "اعتماد مستخلص {$claim->claim_number}", 'description_en' => "Approve claim {$claim->claim_number}", 'status' => 'posted']);
            $journal->lines()->createMany([['account_id' => $data['receivable_account_id'], 'debit' => $claim->net_amount, 'credit' => 0, 'description' => 'ذمم مدينة - مستخلص مشروع'], ['account_id' => $data['revenue_account_id'], 'debit' => 0, 'credit' => $claim->net_amount, 'description' => 'إيراد مستخلص مشروع']]);
            $claim->update(['status' => 'approved', 'approved_at' => now()->toDateString(), 'revenue_journal_entry_id' => $journal->id]);
            \App\Models\WorkflowTransaction::capture('project:claim:approved:' . $claim->id, $claim, 'claim_approved', ['net_amount' => $claim->net_amount], $journal->id);
            return $claim->fresh(['project', 'revenueJournalEntry']);
        });
        return response()->json(['status' => true, 'message' => 'تم اعتماد المستخلص وترحيل إيراد المشروع', 'data' => $updated]);
    }

    public function collectClaim(Request $request, ProjectClaim $claim)
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0'], 'cash_account_id' => ['required', 'exists:accounts,id'], 'receivable_account_id' => ['required', 'exists:accounts,id']]);
        abort_if(!in_array($claim->status, ['approved', 'partially_paid']), 422, 'المستخلص غير جاهز للتحصيل');
        abort_if((float) $data['amount'] > ((float) $claim->net_amount - (float) $claim->paid_amount), 422, 'قيمة التحصيل أكبر من المتبقي');
        $updated = DB::transaction(function () use ($claim, $data) {
            $journal = JournalEntry::create(['entry_date' => now()->toDateString(), 'description_ar' => "تحصيل مستخلص {$claim->claim_number}", 'description_en' => "Collect claim {$claim->claim_number}", 'status' => 'posted']);
            $journal->lines()->createMany([['account_id' => $data['cash_account_id'], 'debit' => $data['amount'], 'credit' => 0, 'description' => 'تحصيل مستخلص مشروع'], ['account_id' => $data['receivable_account_id'], 'debit' => 0, 'credit' => $data['amount'], 'description' => 'تسوية ذمم المستخلص']]);
            $paid = (float) $claim->paid_amount + (float) $data['amount'];
            $claim->update(['paid_amount' => $paid, 'status' => $paid >= (float) $claim->net_amount ? 'paid' : 'partially_paid', 'paid_at' => $paid >= (float) $claim->net_amount ? now()->toDateString() : null, 'collection_journal_entry_id' => $journal->id]);
            \App\Models\WorkflowTransaction::capture('project:claim:collect:' . $claim->id . ':' . $journal->id, $claim, 'claim_collected', ['amount' => $data['amount'], 'paid_amount' => $paid], $journal->id);
            return $claim->fresh(['project', 'collectionJournalEntry']);
        });
        return response()->json(['status' => true, 'message' => 'تم تحصيل المستخلص وتحديث رصيد المشروع', 'data' => $updated]);
    }
}
