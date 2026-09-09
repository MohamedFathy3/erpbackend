<?php
namespace App\Http\Controllers;

use App\Models\CrmActivity;
use App\Models\Deal;
use App\Models\EmailLog;
use App\Models\Lead;
use App\Models\PipelineStage;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CrmAnalyticsController extends Controller
{
    public function overview(Request $request)
    {
        $from = $request->date('from')?->startOfDay() ?? now()->subDays(30)->startOfDay();
        $to = $request->date('to')?->endOfDay() ?? now()->endOfDay();
        abort_if($from->greaterThan($to), 422, 'The from date must be before the to date.');

        $deals = Deal::with(['stage','assignee'])->whereBetween('created_at', [$from, $to])->get();
        $leads = Lead::with('assignee')->whereBetween('created_at', [$from, $to])->get();
        $activities = CrmActivity::whereBetween('occurred_at', [$from, $to])->get();
        $emails = EmailLog::whereBetween('created_at', [$from, $to])->get();
        $stages = PipelineStage::orderBy('sort_order')->get();

        $won = $deals->filter(fn ($deal) => (bool) $deal->stage?->is_won);
        $lost = $deals->filter(fn ($deal) => (bool) $deal->stage?->is_lost);
        $closed = $won->count() + $lost->count();
        $periodDays = max(1, $from->diffInDays($to) + 1);

        return response()->json(['data' => [
            'period' => ['from'=>$from->toDateString(), 'to'=>$to->toDateString(), 'days'=>$periodDays],
            'summary' => [
                'leads' => $leads->count(), 'deals' => $deals->count(),
                'pipeline_value' => round((float) $deals->sum('value'), 2),
                'won_value' => round((float) $won->sum('value'), 2),
                'won_deals' => $won->count(), 'lost_deals' => $lost->count(),
                'conversion_rate' => $leads->count() ? round(($won->count() / $leads->count()) * 100, 2) : 0,
                'win_rate' => $closed ? round(($won->count() / $closed) * 100, 2) : 0,
                'activities' => $activities->count(), 'emails_queued' => $emails->count(),
                'emails_sent' => $emails->where('status','sent')->count(),
                'email_success_rate' => $emails->count() ? round(($emails->where('status','sent')->count() / $emails->count()) * 100, 2) : 0,
            ],
            'funnel' => $stages->map(fn ($stage) => ['id'=>$stage->id, 'name'=>$stage->name, 'name_ar'=>$stage->name_ar, 'sort_order'=>$stage->sort_order, 'deals'=>$deals->where('stage_id',$stage->id)->count(), 'value'=>round((float)$deals->where('stage_id',$stage->id)->sum('value'),2), 'is_won'=>(bool)$stage->is_won, 'is_lost'=>(bool)$stage->is_lost])->values(),
            'lead_sources' => $leads->groupBy(fn ($lead) => $lead->source ?: 'unknown')->map(fn ($items, $source) => ['source'=>$source, 'leads'=>$items->count(), 'converted'=>$items->where('status','converted')->count(), 'conversion_rate'=>$items->count() ? round(($items->where('status','converted')->count() / $items->count()) * 100, 2) : 0])->values(),
            'activities_by_type' => $activities->groupBy('type')->map(fn ($items, $type) => ['type'=>$type, 'count'=>$items->count()])->values(),
            'assignee_performance' => $deals->groupBy('assigned_to')->map(function ($items, $assigneeId) use ($leads) { $won = $items->filter(fn ($deal) => (bool) $deal->stage?->is_won); return ['assignee_id'=>$assigneeId, 'assignee_name'=>$items->first()?->assignee?->name ?: 'Unassigned', 'deals'=>$items->count(), 'won'=>$won->count(), 'pipeline_value'=>round((float)$items->sum('value'),2), 'won_value'=>round((float)$won->sum('value'),2)]; })->values(),
            'daily_trend' => $this->dailyTrend($from, $to, $leads, $deals, $activities, $emails),
        ]]);
    }

    private function dailyTrend(Carbon $from, Carbon $to, $leads, $deals, $activities, $emails): array
    {
        $rows = [];
        for ($date = $from->copy()->startOfDay(); $date->lte($to); $date->addDay()) {
            $key = $date->toDateString();
            $rows[] = ['date'=>$key, 'leads'=>$leads->filter(fn ($item) => $item->created_at?->toDateString() === $key)->count(), 'deals'=>$deals->filter(fn ($item) => $item->created_at?->toDateString() === $key)->count(), 'activities'=>$activities->filter(fn ($item) => $item->occurred_at?->toDateString() === $key)->count(), 'emails'=>$emails->filter(fn ($item) => $item->created_at?->toDateString() === $key)->count()];
        }
        return $rows;
    }
}
