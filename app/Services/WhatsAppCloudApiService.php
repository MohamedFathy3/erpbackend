<?php

namespace App\Services;

use App\Models\WhatsappAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WhatsAppCloudApiService
{
    public function templatesFor(WhatsappAccount $account): array
    {
        $response = $this->requestFor($account)->get($this->graphUrl($account, $account->business_account_id . '/message_templates'), [
            'fields' => 'name,status,language,category,components',
            'limit' => 250,
        ]);
        if (!$response->successful()) {
            throw new RuntimeException('Meta templates error: ' . $response->body());
        }
        return $response->json('data', []);
    }

    public function sendTextFor(WhatsappAccount $account, string $to, string $body): Response
    {
        return $this->sendFor($account, [
            'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to,
            'type' => 'text', 'text' => ['preview_url' => false, 'body' => $body],
        ]);
    }

    public function sendTemplateFor(WhatsappAccount $account, string $to, string $name, string $languageCode, array $components = []): Response
    {
        if (isset($components['body']) && is_array($components['body'])) {
            $components = [['type' => 'body'] + $components['body']];
        }
        return $this->sendFor($account, [
            'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to,
            'type' => 'template', 'template' => array_filter([
                'name' => $name, 'language' => ['code' => $languageCode], 'components' => $components ?: null,
            ]),
        ]);
    }

    public function testConnection(WhatsappAccount $account): array
    {
        $response = $this->requestFor($account)->get($this->graphUrl($account, $account->phone_number_id), [
            'fields' => 'id,display_phone_number,verified_name,quality_rating',
        ]);
        if (!$response->successful()) {
            throw new RuntimeException('Meta connection test failed: ' . $response->body());
        }
        return $response->json() ?? [];
    }

    public function templates(): array
    {
        $businessAccountId = config('whatsapp.business_account_id');
        $token = config('whatsapp.access_token');
        $version = config('whatsapp.graph_version', 'v23.0');

        if (!$businessAccountId || !$token) {
            throw new RuntimeException('WhatsApp Cloud API is not configured.');
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('whatsapp.timeout', 15))
            ->get("https://graph.facebook.com/{$version}/{$businessAccountId}/message_templates", [
                'fields' => 'name,status,language,category,components',
                'limit' => 250,
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('Meta templates error: ' . $response->body());
        }

        return $response->json('data', []);
    }

    public function sendText(string $to, string $body): Response
    {
        return $this->send([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $body],
        ]);
    }

    public function sendTemplate(string $to, string $name, string $languageCode, array $components = []): Response
    {
        // The UI may send {body: {parameters: [...]}}; Meta expects a list of components.
        if (isset($components['body']) && is_array($components['body'])) {
            $components = [['type' => 'body'] + $components['body']];
        }

        return $this->send([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => array_filter([
                'name' => $name,
                'language' => ['code' => $languageCode],
                'components' => $components ?: null,
            ]),
        ]);
    }

    private function sendFor(WhatsappAccount $account, array $payload): Response
    {
        return $this->requestFor($account)->post($this->graphUrl($account, $account->phone_number_id . '/messages'), $payload);
    }

    private function requestFor(WhatsappAccount $account)
    {
        if (!$account->access_token || !$account->phone_number_id || !$account->business_account_id) {
            throw new RuntimeException('WhatsApp account is not fully configured.');
        }
        return Http::withToken($account->access_token)
            ->acceptJson()
            ->timeout((int) config('whatsapp.timeout', 15));
    }

    private function graphUrl(WhatsappAccount $account, string $path): string
    {
        $version = config('whatsapp.graph_version', 'v23.0');
        return "https://graph.facebook.com/{$version}/{$path}";
    }

    private function send(array $payload): Response
    {
        $phoneNumberId = config('whatsapp.phone_number_id');
        $token = config('whatsapp.access_token');
        $version = config('whatsapp.graph_version', 'v23.0');

        if (!$phoneNumberId || !$token) {
            throw new RuntimeException('WhatsApp Cloud API is not configured.');
        }

        return Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('whatsapp.timeout', 15))
            ->post("https://graph.facebook.com/{$version}/{$phoneNumberId}/messages", $payload);
    }
}


// phpcs:disable
/* Meta Cloud API message endpoint: https://developers.facebook.com/docs/whatsapp/cloud-api/reference/messages */
// Webhook status reference: https://developers.facebook.com/documentation/business-messaging/whatsapp/webhooks/reference/messages/status
// phpcs:enable
