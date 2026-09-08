<?php
namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\Request;

class TrialManagementController extends Controller
{
    private function guard(Request $request): void { abort_unless($request->user()?->super_admin, 403); }
    public function index(Request $request) { $this->guard($request); $items=Tenant::withoutGlobalScopes()->where('subscription_status','trial')->latest()->get()->map(fn($tenant)=>$this->view($tenant)); return response()->json(['data'=>$items]); }
    public function extend(Request $request, Tenant $tenant) { $this->guard($request); $data=$request->validate(['days'=>'required|integer|min:1|max:365']); $base=$tenant->trial_ends_at?->isFuture() ? $tenant->trial_ends_at : now(); $tenant->update(['trial_ends_at'=>$base->addDays($data['days']),'subscription_status'=>'trial','status'=>'trial']); activity()->causedBy($request->user())->performedOn($tenant)->withProperties($data)->log('trial extended'); return response()->json(['data'=>$this->view($tenant->fresh())]); }
    public function activate(Request $request, Tenant $tenant) { $this->guard($request); $tenant->update(['subscription_status'=>'active','status'=>'active']); activity()->causedBy($request->user())->performedOn($tenant)->log('trial converted to active'); return response()->json(['data'=>$tenant->fresh()]); }
    public function suspend(Request $request, Tenant $tenant) { $this->guard($request); $tenant->update(['subscription_status'=>'suspended','status'=>'suspended']); activity()->causedBy($request->user())->performedOn($tenant)->log('trial suspended'); return response()->json(['data'=>$tenant->fresh()]); }
    private function view(Tenant $tenant): array { return ['id'=>$tenant->id,'name'=>$tenant->name,'slug'=>$tenant->slug,'plan'=>$tenant->plan,'trial_starts_at'=>$tenant->trial_starts_at,'trial_ends_at'=>$tenant->trial_ends_at,'days_remaining'=>$tenant->trial_ends_at ? max(0, now()->startOfDay()->diffInDays($tenant->trial_ends_at->startOfDay(), false)) : null,'subscription_status'=>$tenant->subscription_status]; }
}
