<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WhatsAppCloudApiService
{
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
