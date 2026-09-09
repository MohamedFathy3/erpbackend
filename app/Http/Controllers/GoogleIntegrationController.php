<?php

namespace App\Http\Controllers;

use App\Models\CalendarEvent;
use App\Models\GoogleConnection;
use App\Models\Admin;
use App\Services\GoogleCalendarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GoogleIntegrationController extends Controller
{
    public function __construct(private readonly GoogleCalendarService $google) {}

    public function authUrl(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401);
        abort_unless(config('google.client_id') && config('google.client_secret'), 503, 'Google OAuth is not configured on the server.');

        $state = Crypt::encryptString(json_encode([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'nonce' => Str::random(40),
            'issued_at' => now()->timestamp,
        ], JSON_THROW_ON_ERROR));

        return response()->json(['data' => ['url' => $this->google->authorizationUrl($state)]]);
    }

    public function callback(Request $request): RedirectResponse
    {
        $redirect = config('google.frontend_redirect_uri');
        try {
            abort_if(!$request->filled('code') || !$request->filled('state'), 422, 'Missing Google OAuth callback parameters.');
            $state = json_decode(Crypt::decryptString((string) $request->string('state')), true, 512, JSON_THROW_ON_ERROR);
            abort_if(($state['issued_at'] ?? 0) < now()->subMinutes(10)->timestamp, 403, 'Google OAuth state expired.');
            // The ERP authenticates admins, not the legacy users table.
            $user = Admin::query()->findOrFail((int) $state['user_id']);
            $result = $this->google->exchangeCode((string) $request->string('code'));
            $token = $result['token'];
            $profile = $result['profile'];

            GoogleConnection::updateOrCreate(
                ['user_id' => $user->id, 'tenant_id' => $user->tenant_id],
                [
                    'access_token' => $token['access_token'],
                    'refresh_token' => $token['refresh_token'] ?? GoogleConnection::query()->where('user_id', $user->id)->value('refresh_token'),
                    'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
                    'google_account_id' => $profile->getId(),
                    'google_email' => $profile->getEmail(),
                    'google_name' => $profile->getName(),
                    'scopes' => $result['scopes'],
                    'status' => 'connected',
                ]
            );

            return redirect()->to($redirect . (str_contains($redirect, '?') ? '&' : '?') . 'google=connected');
        } catch (\Throwable $e) {
            report($e);
            return redirect()->to($redirect . (str_contains($redirect, '?') ? '&' : '?') . 'google=error');
        }
    }

    public function status(Request $request)
    {
        $connection = $this->connectionFor($request);
        return response()->json(['data' => $connection ? [
            'connected' => true,
            'status' => $connection->status,
            'google_email' => $connection->google_email,
            'google_name' => $connection->google_name,
            'scopes' => $connection->scopes ? explode(' ', $connection->scopes) : [],
            'connected_at' => $connection->created_at,
        ] : ['connected' => false]]);
    }

    public function disconnect(Request $request)
    {
        $connection = $this->connectionFor($request);
        $connection?->update(['status' => 'revoked', 'access_token' => '', 'refresh_token' => null]);
        return response()->json(['message' => 'Google connection disconnected.']);
    }

    public function events(Request $request)
    {
        return response()->json(['data' => CalendarEvent::query()->latest('starts_at')->paginate($request->integer('per_page', 25))]);
    }

    public function storeEvent(Request $request)
    {
        $data = $request->validate([
            'summary' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'location' => 'nullable|string|max:500',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
            'calendar_id' => 'nullable|string|max:255',
        ]);
        $connection = $this->connectionFor($request);
        if (!$connection || $connection->status !== 'connected') {
            throw ValidationException::withMessages(['google' => 'Connect a Google account before creating calendar events.']);
        }

        $created = $this->google->createEvent($connection, $data);
        $event = CalendarEvent::create([
            'user_id' => $request->user()->id,
            'tenant_id' => $request->user()->tenant_id,
            'google_connection_id' => $connection->id,
            'google_event_id' => $created['id'],
            'calendar_id' => $data['calendar_id'] ?? 'primary',
            'summary' => $data['summary'],
            'description' => $data['description'] ?? null,
            'location' => $data['location'] ?? null,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'google_payload' => $created['payload'],
        ]);

        return response()->json(['data' => ['event' => $event, 'html_link' => $created['html_link']]], 201);
    }

    private function connectionFor(Request $request): ?GoogleConnection
    {
        return GoogleConnection::query()->where('user_id', $request->user()->id)->first();
    }
}
