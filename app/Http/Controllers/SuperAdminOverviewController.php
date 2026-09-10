<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuperAdminOverviewController extends Controller
{
    private function guard(Request $request): void
    {
        abort_unless($request->user() && ($request->user()->super_admin ?? false), 403);
    }

    public function index(Request $request)
    {
        $this->guard($request);
        $since = now()->subDays(30);
        $count = static fn (string $table): int => DB::table($table)->count();
        $stats = [
            'tenants' => $count('tenants'),
            'active_tenants' => DB::table('tenants')->where('status', 'active')->count(),
            'trial_tenants' => DB::table('tenants')->where('subscription_status', 'trial')->count(),
            'admins' => $count('admins'),
            'users' => $count('users'),
            'employees' => $count('employees'),
            'products' => $count('products'),
            'sales_invoices' => $count('sales_invoices'),
            'activities_30d' => DB::table('activity_log')->where('created_at', '>=', $since)->count(),
            'logins_30d' => DB::table('activity_log')->where('description', 'login')->where('created_at', '>=', $since)->count(),
        ];
        $recent = DB::table('activity_log')
            ->leftJoin('admins', function ($join) { $join->on('admins.id', '=', 'activity_log.causer_id')->where('activity_log.causer_type', 'App\\Models\\Admin'); })
            ->leftJoin('users', function ($join) { $join->on('users.id', '=', 'activity_log.causer_id')->where('activity_log.causer_type', 'App\\Models\\User'); })
            ->select('activity_log.id', 'activity_log.description', 'activity_log.subject_type', 'activity_log.subject_id', 'activity_log.causer_type', 'activity_log.causer_id', 'activity_log.created_at', DB::raw("COALESCE(admins.name, users.name, 'System') as actor_name"))
            ->latest('activity_log.created_at')->limit(50)->get();
        $tenants = DB::table('tenants')->leftJoin('admins', 'admins.tenant_id', '=', 'tenants.id')->leftJoin('products', 'products.tenant_id', '=', 'tenants.id')->select('tenants.id','tenants.name','tenants.slug','tenants.status','tenants.subscription_status','tenants.trial_ends_at',DB::raw('COUNT(DISTINCT admins.id) as admins_count'),DB::raw('COUNT(DISTINCT products.id) as products_count'))->groupBy('tenants.id','tenants.name','tenants.slug','tenants.status','tenants.subscription_status','tenants.trial_ends_at')->orderByDesc('tenants.id')->limit(100)->get();
        return response()->json(['data' => compact('stats', 'recent', 'tenants')]);
    }
}
