<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Services\GoogleCalendarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class GoogleAuthController extends Controller
{
    public function __construct(private readonly GoogleCalendarService $google) {}

    public function url()
    {
        abort_unless(config('google.client_id') && config('google.client_secret'), 503, 'Google OAuth is not configured on the server.');
        $state = Crypt::encryptString(json_encode([
            'purpose' => 'signin',
            'nonce' => Str::random(40),
            'issued_at' => now()->timestamp,
        ], JSON_THROW_ON_ERROR));

        return response()->json(['data' => ['url' => $this->google->authorizationUrl($state, config('google.auth_redirect_uri'))]]);
    }

    public function callback(Request $request): RedirectResponse
    {
        $redirect = config('google.frontend_auth_redirect_uri', config('google.frontend_redirect_uri'));
        try {
            abort_if(!$request->filled('code') || !$request->filled('state'), 422, 'Missing Google OAuth callback parameters.');
            $state = json_decode(Crypt::decryptString((string) $request->string('state')), true, 512, JSON_THROW_ON_ERROR);
            abort_unless(($state['purpose'] ?? null) === 'signin', 403, 'Invalid Google OAuth state.');
            abort_if(($state['issued_at'] ?? 0) < now()->subMinutes(10)->timestamp, 403, 'Google OAuth state expired.');

            $result = $this->google->exchangeCode((string) $request->string('code'), config('google.auth_redirect_uri'));
            $profile = $result['profile'];
            abort_unless($profile->getEmail(), 422, 'Google account did not provide an email address.');

            $admin = Admin::query()->withTrashed()->where('email', $profile->getEmail())->first();
            if (!$admin) {
                $admin = Admin::create([
                    'name' => $profile->getName() ?: $profile->getEmail(),
                    'email' => $profile->getEmail(),
                    'password' => Hash::make(Str::random(48)),
                    'active' => true,
                ]);
            } elseif ($admin->trashed()) {
                $admin->restore();
            }

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
}
