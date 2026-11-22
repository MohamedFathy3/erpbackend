<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Services\GoogleCalendarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class GoogleAuthController extends Controller
{
    public function __construct(private readonly GoogleCalendarService $google) {}

    public function url(Request $request)
    {
        abort_unless(config('google.client_id') && config('google.client_secret'), 503, 'Google OAuth is not configured on the server.');
        $tenantSlug = $this->tenantSlugFromOrigin($request);
        if ($tenantSlug) {
            abort_unless(Tenant::query()->where('slug', $tenantSlug)->exists(), 404, 'Tenant workspace was not found.');
        }

        $state = Crypt::encryptString(json_encode([
            'purpose' => 'signin',
            'tenant_slug' => $tenantSlug,
            'nonce' => Str::random(40),
            'issued_at' => now()->timestamp,
        ], JSON_THROW_ON_ERROR));

        return response()->json(['data' => ['url' => $this->google->authorizationUrl($state, config('google.auth_redirect_uri'), ['openid', 'email', 'profile'])]]);
    }

    public function callback(Request $request): RedirectResponse
    {
        $redirect = config('google.frontend_auth_redirect_uri', config('google.frontend_redirect_uri'));
        try {
            abort_if(!$request->filled('code') || !$request->filled('state'), 422, 'Missing Google OAuth callback parameters.');
            $state = json_decode(Crypt::decryptString((string) $request->string('state')), true, 512, JSON_THROW_ON_ERROR);
            abort_unless(($state['purpose'] ?? null) === 'signin', 403, 'Invalid Google OAuth state.');
            abort_if(($state['issued_at'] ?? 0) < now()->subMinutes(10)->timestamp, 403, 'Google OAuth state expired.');

            $tenant = null;
            if (!empty($state['tenant_slug'])) {
                $tenant = Tenant::query()->where('slug', (string) $state['tenant_slug'])->firstOrFail();
                $redirect = $this->tenantAuthUrl($tenant->slug);
            }

            $result = $this->google->exchangeCode((string) $request->string('code'), config('google.auth_redirect_uri'));
            $profile = $result['profile'];
            $email = trim((string) $profile->getEmail());
            abort_unless($email !== '', 422, 'Google account did not provide an email address.');

            $admin = Admin::withoutGlobalScopes()->withTrashed()->where('email', $email)->first();
            if ($tenant) {
                if ($admin && (int) $admin->tenant_id !== (int) $tenant->id) {
                    abort(403, 'This Google account is not registered in the requested workspace.');
                }
                if (!$admin) {
                    $admin = $this->createTenantAdmin($tenant, $profile, $email);
                }
            } elseif (!$admin) {
                [$tenant, $admin] = $this->createGoogleWorkspace($profile, $email);
                $redirect = $this->tenantAuthUrl($tenant->slug);
            } elseif (!$admin->super_admin && $admin->tenant_id) {
                $tenant = Tenant::query()->findOrFail((int) $admin->tenant_id);
                $redirect = $this->tenantAuthUrl($tenant->slug);
            }

            if ($admin->trashed()) $admin->restore();
            abort_unless($admin->active, 403, 'This account is inactive.');
            $token = $admin->createToken('google-login')->plainTextToken;
            $separator = str_contains($redirect, '?') ? '&' : '?';

            return redirect()->to($redirect . $separator . 'google_token=' . urlencode($token));
        } catch (\Throwable $e) {
            report($e);
            $separator = str_contains($redirect, '?') ? '&' : '?';
            return redirect()->to($redirect . $separator . 'google=error');
        }
    }

    private function createTenantAdmin(Tenant $tenant, object $profile, string $email): Admin
    {
        return DB::transaction(function () use ($tenant, $profile, $email): Admin {
            $role = Role::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'Admin'],
                ['created_at' => now(), 'updated_at' => now()],
            );
            if ($role->wasRecentlyCreated) {
                $role->permissions()->sync(Permission::withoutGlobalScopes()->pluck('id'));
            }

            return Admin::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'role_id' => $role->id,
                'name' => $profile->getName() ?: $email,
                'email' => $email,
                'password' => Hash::make(Str::random(48)),
                'active' => true,
                'super_admin' => false,
            ]);
        });
    }

    private function createGoogleWorkspace(object $profile, string $email): array
    {
        return DB::transaction(function () use ($profile, $email): array {
            $name = trim((string) $profile->getName()) ?: Str::before($email, '@');
            $baseSlug = Str::slug($name) ?: 'workspace';
            $slug = $baseSlug;
            $suffix = 1;
            while (Tenant::withoutGlobalScopes()->where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . (++$suffix);
            }

            $tenant = Tenant::withoutGlobalScopes()->create([
                'name' => $name,
                'slug' => $slug,
                'status' => 'trial',
                'plan' => 'trial',
                'trial_starts_at' => now(),
                'trial_ends_at' => now()->addDays(5),
                'subscription_status' => 'trial',
            ]);
            foreach (TenantModule::available() as $moduleKey) {
                TenantModule::withoutGlobalScopes()->create([
                    'tenant_id' => $tenant->id,
                    'module_key' => $moduleKey,
                    'is_enabled' => true,
                ]);
            }

            $roles = [];
            foreach (['Admin', 'Manager', 'Sales', 'Purchasing', 'Accountant', 'Cashier', 'Warehouse', 'HR', 'Customer Support', 'Viewer'] as $roleName) {
                $role = Role::withoutGlobalScopes()->create([
                    'tenant_id' => $tenant->id,
                    'name' => $roleName,
                ]);
                if ($roleName === 'Admin') {
                    $role->permissions()->sync(Permission::withoutGlobalScopes()->pluck('id'));
                }
                $roles[$roleName] = $role;
            }

            $admin = Admin::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'role_id' => $roles['Admin']->id,
                'name' => $name,
                'email' => $email,
                'password' => Hash::make(Str::random(48)),
                'active' => true,
                'super_admin' => false,
            ]);

            return [$tenant, $admin];
        });
    }

    private function tenantSlugFromOrigin(Request $request): ?string
    {
        $origin = (string) $request->header('Origin', '');
        $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
        if ($host === '') return null;

        $centralDomains = config('tenancy.central_domains', '');
        $centralDomains = is_array($centralDomains) ? $centralDomains : explode(',', (string) $centralDomains);
        $centralDomains = array_map(fn ($domain) => strtolower(trim((string) $domain)), $centralDomains);
        if (in_array($host, $centralDomains, true)) return null;

        $rootDomains = config('tenancy.root_domains', [config('tenancy.root_domain', '')]);
        $rootDomains = is_array($rootDomains) ? $rootDomains : explode(',', (string) $rootDomains);
        $rootDomains = array_values(array_filter(array_map(fn ($domain) => strtolower(trim((string) $domain)), $rootDomains)));
        usort($rootDomains, fn ($left, $right) => strlen($right) <=> strlen($left));
        foreach ($rootDomains as $rootDomain) {
            if (!str_ends_with($host, '.' . $rootDomain)) continue;
            $slug = substr($host, 0, -(strlen($rootDomain) + 1));
            if ($slug !== '' && !str_contains($slug, '.') && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                return $slug;
            }
        }

        return null;
    }

    private function tenantAuthUrl(string $slug): string
    {
        $frontendUrl = (string) config('app.frontend_url', config('app.url'));
        $scheme = parse_url($frontendUrl, PHP_URL_SCHEME) ?: 'https';
        return $scheme . '://' . strtolower($slug) . '.' . config('tenancy.root_domain') . '/auth';
    }
}
