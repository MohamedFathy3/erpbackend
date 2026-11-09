<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\WhatsappAccount;
use App\Models\WhatsappConversation;
use App\Models\WhatsappConversationMessage;
use App\Models\WhatsappMessage;
use App\Models\WhatsappWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WhatsappWebhookController extends Controller
{
    public function verify(Request $request, string $webhookKey)
    {
        $account = $this->findAccount($webhookKey);
        $mode = (string) $request->query('hub.mode', '');
        $token = (string) $request->query('hub.verify_token', '');
        abort_unless($mode === 'subscribe' && $token !== '' && hash_equals((string) $account->webhook_verify_token, $token), 403);

        return response((string) $request->query('hub.challenge', ''), 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, string $webhookKey): JsonResponse
    {
        $account = $this->findAccount($webhookKey);
        abort_unless($account->is_active, 404);

        $appSecret = $account->app_secret ?: config('whatsapp.app_secret');
        abort_if(!$appSecret, 503, 'Webhook signature validation is not configured.');
        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $appSecret);
        abort_unless($signature !== '' && hash_equals($expected, $signature), 401);

        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $metadataPhoneId = (string) data_get($value, 'metadata.phone_number_id', '');
                if ($metadataPhoneId !== '' && !hash_equals((string) $account->phone_number_id, $metadataPhoneId)) {
                    abort(403, 'Webhook phone number does not match this account.');
                }

                foreach ($value['messages'] ?? [] as $message) {
                    $this->storeInbound($account, $message, $value);
                }
                foreach ($value['statuses'] ?? [] as $status) {
                    $this->storeStatus($account, $status);
                }
            }
        }

        return response()->json(['status' => 'ok']);
    }

    private function findAccount(string $webhookKey): WhatsappAccount
    {
        abort_unless(Str::isUuid($webhookKey), 404);
        return WhatsappAccount::withoutGlobalScopes()->where('webhook_key', $webhookKey)->firstOrFail();
    }

    private function storeInbound(WhatsappAccount $account, array $message, array $value): void
    {
        $providerId = (string) ($message['id'] ?? '');
        $phone = $this->normalizePhone((string) ($message['from'] ?? ''));
        if ($providerId === '' || $phone === '') {
            return;
        }

        $event = $this->claimEvent($account, 'message:' . $providerId, 'message', $message);
        if (!$event) {
            return;
        }

        DB::transaction(function () use ($account, $message, $value, $providerId, $phone, $event): void {
            $profileName = collect(data_get($value, 'contacts', []))
                ->first(fn ($contact) => ($contact['wa_id'] ?? null) === ($message['from'] ?? null))['profile']['name'] ?? null;

            $conversation = WhatsappConversation::withoutGlobalScopes()->firstOrCreate(
                ['whatsapp_account_id' => $account->id, 'contact_phone' => $phone],
                ['tenant_id' => $account->tenant_id, 'contact_name' => $profileName, 'status' => 'bot_active']
            );
            if ($profileName && !$conversation->contact_name) {
                $conversation->contact_name = $profileName;
            }
            $timestamp = isset($message['timestamp']) ? Carbon::createFromTimestamp((int) $message['timestamp']) : now();
            $body = data_get($message, 'text.body')
                ?? data_get($message, 'button.text')
                ?? data_get($message, 'interactive.button_reply.title')
                ?? data_get($message, 'interactive.list_reply.title')
                ?? data_get($message, 'image.caption')
                ?? data_get($message, 'video.caption');

            WhatsappConversationMessage::withoutGlobalScopes()->firstOrCreate(
                ['provider_message_id' => $providerId],
                [
                    'tenant_id' => $account->tenant_id,
                    'whatsapp_account_id' => $account->id,
                    'conversation_id' => $conversation->id,
                    'direction' => 'inbound',
                    'message_type' => (string) ($message['type'] ?? 'unknown'),
                    'body' => is_string($body) ? Str::limit($body, 10000, '') : null,
                    'payload' => $message,
                    'provider_timestamp' => $timestamp,
                ]
            );

            $conversation->forceFill(['last_inbound_at' => $timestamp])->save();
            Customer::withoutGlobalScopes()
                ->where('tenant_id', $account->tenant_id)
                ->where('phone', $phone)
                ->update(['whatsapp_last_inbound_at' => $timestamp]);
            $event->forceFill(['status' => 'processed', 'processed_at' => now()])->save();
        });
    }

    private function storeStatus(WhatsappAccount $account, array $status): void
    {
        $providerId = (string) ($status['id'] ?? '');
        $statusName = (string) ($status['status'] ?? 'unknown');
        if ($providerId === '') {
            return;
        }
        $event = $this->claimEvent($account, 'status:' . $providerId . ':' . $statusName . ':' . (string) data_get($status, 'timestamp', ''), 'status', $status);
        if (!$event) {
            return;
        }

        $outbound = WhatsappMessage::withoutGlobalScopes()
            ->where('tenant_id', $account->tenant_id)
            ->where('provider_message_id', $providerId)
            ->first();
        if ($outbound) {
            $allowed = ['sent', 'delivered', 'read', 'failed'];
            if (in_array($statusName, $allowed, true)) {
                $outbound->status = $statusName;
                $outbound->error_code = data_get($status, 'errors.0.code');
                $outbound->error_message = data_get($status, 'errors.0.title');
                $timestamp = isset($status['timestamp']) ? Carbon::createFromTimestamp((int) $status['timestamp']) : now();
                $outbound->{$statusName . '_at'} = $timestamp;
                $outbound->meta_response = $status;
                $outbound->save();
            }
        }
        $event->forceFill(['status' => 'processed', 'processed_at' => now()])->save();
    }

    private function claimEvent(WhatsappAccount $account, string $key, string $type, array $payload): ?WhatsappWebhookEvent
    {
        $dedupeKey = Str::limit($account->id . ':' . $key, 191, '');
        try {
            return WhatsappWebhookEvent::withoutGlobalScopes()->create([
                'tenant_id' => $account->tenant_id,
                'whatsapp_account_id' => $account->id,
                'dedupe_key' => $dedupeKey,
                'event_type' => $type,
                'status' => 'received',
                'payload' => $payload,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return null;
        }
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        return $digits && strlen($digits) >= 8 && strlen($digits) <= 15 ? '+' . $digits : '';
    }
}
