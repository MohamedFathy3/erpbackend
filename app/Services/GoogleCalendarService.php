<?php

namespace App\Services;

use App\Models\GoogleConnection;
use Google\Client as GoogleClient;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Illuminate\Support\Carbon;
use RuntimeException;

class GoogleCalendarService
{
    public function client(?GoogleConnection $connection = null): GoogleClient
    {
        $client = new GoogleClient();
        $client->setClientId((string) config('google.client_id'));
        $client->setClientSecret((string) config('google.client_secret'));
        $client->setRedirectUri((string) config('google.redirect_uri'));
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setScopes(config('google.scopes', []));

        if ($connection) {
            $client->setAccessToken([
                'access_token' => $connection->access_token,
                'refresh_token' => $connection->refresh_token,
                'expires_in' => max(0, now()->diffInSeconds($connection->token_expires_at, false)),
                'created' => now()->timestamp,
            ]);
        }

        return $client;
    }

    public function authorizationUrl(string $state, ?string $redirectUri = null): string
    {
        $client = $this->client();
        if ($redirectUri) {
            $client->setRedirectUri($redirectUri);
        }
        $client->setState($state);
        return $client->createAuthUrl();
    }

    public function exchangeCode(string $code, ?string $redirectUri = null): array
    {
        $client = $this->client();
        if ($redirectUri) {
            $client->setRedirectUri($redirectUri);
        }
        $token = $client->fetchAccessTokenWithAuthCode($code);
        if (isset($token['error'])) {
            throw new RuntimeException($token['error_description'] ?? $token['error']);
        }

        $client->setAccessToken($token);
        $oauth = new \Google\Service\Oauth2($client);
        $profile = $oauth->userinfo->get();

        return [
            'token' => $token,
            'profile' => $profile,
            'scopes' => $token['scope'] ?? implode(' ', config('google.scopes', [])),
        ];
    }

    public function ensureValidToken(GoogleConnection $connection): GoogleClient
    {
        $client = $this->client($connection);
        if ($client->isAccessTokenExpired() && $connection->refresh_token) {
            $token = $client->fetchAccessTokenWithRefreshToken($connection->refresh_token);
            if (isset($token['error'])) {
                $connection->update(['status' => 'reauthorization_required']);
                throw new RuntimeException($token['error_description'] ?? $token['error']);
            }
            $connection->update([
                'access_token' => $token['access_token'],
                'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
                'status' => 'connected',
            ]);
            $client->setAccessToken(array_merge($token, ['refresh_token' => $connection->refresh_token]));
        }

        return $client;
    }

    public function createEvent(GoogleConnection $connection, array $data): array
    {
        $client = $this->ensureValidToken($connection);
        $calendar = new Calendar($client);
        $event = new Event([
            'summary' => $data['summary'],
            'description' => $data['description'] ?? null,
            'location' => $data['location'] ?? null,
            'start' => ['dateTime' => Carbon::parse($data['starts_at'])->toRfc3339String(), 'timeZone' => config('app.timezone')],
            'end' => ['dateTime' => Carbon::parse($data['ends_at'])->toRfc3339String(), 'timeZone' => config('app.timezone')],
        ]);
        $created = $calendar->events->insert($data['calendar_id'] ?? 'primary', $event);

        return [
            'id' => $created->getId(),
            'html_link' => $created->getHtmlLink(),
            'payload' => json_decode($created->toSimpleObject() ? json_encode($created->toSimpleObject()) : '{}', true),
        ];
    }
}
