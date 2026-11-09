<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\WhatsappAccount;
use App\Models\WhatsappConversation;
use App\Models\WhatsappConversationMessage;
use App\Models\WhatsappMessage;
use App\Models\WhatsappWebhookEvent;
use App\Jobs\ProcessWhatsAppTenantAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WhatsappWebhookController extends Controller
{
    public function verifyGlobal(Request $request)
    {
        $verifyToken = (string) config('whatsapp.webhook_verify_token', '');
        abort_unless($verifyToken !== '' && $request->query('hub.mode') === 'subscribe'
            && hash_equals($verifyToken, (string) $request->query('hub.verify_token', '')), 403);
        return response((string) $request->query('hub.challenge', ''), 200)->header('Content-Type', 'text/plain');
    }

    public function receiveGlobal(Request $request): JsonResponse
    {
        $secret = (string) config('whatsapp.meta_app_secret', '');
        abort_if($secret === '', 503, 'Webhook signature validation is not configured.');
        $sig = (string) $request->header('X-Hub-Signature-256', '');
        abort_unless($sig !== '' && hash_equals('sha256=' . hash_hmac('sha256', $request->getContent(), $secret), $sig), 401);
        foreach ($request->input('entry', []) as $entry) {
            $waba = (string) ($entry['id'] ?? '');
            foreach ($entry['changes'] ?? [] as $change) {
                $phoneId = (string) data_get($change, 'value.metadata.phone_number_id', '');
                $account = WhatsappAccount::withoutGlobalScopes()->whereNull('deleted_at')->where('is_active', true)
                    ->when($phoneId !== '', fn ($q) => $q->where('phone_number_id', $phoneId), fn ($q) => $q->where('business_account_id', $waba))->first();
                if (!$account) continue;
                // Original raw body has already been authenticated; re-sign its isolated entry for legacy processor.
                $payload = ['object' => $request->input('object'), 'entry' => [['id' => $waba, 'changes' => [$change]]]];
                $raw = json_encode($payload);
                $inner = Request::create('/api/integrations/whatsapp/webhook/' . $account->webhook_key, 'POST', [], [], [], [
                    'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $raw, $secret),
                ], $raw);
                $this->receive($inner, $account->webhook_key);
            }
        }
        return response()->json(['status' => 'ok']);
    }

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
        return WhatsappAccount::withoutGlobalScopes()->whereNull('deleted_at')->where('webhook_key', $webhookKey)->firstOrFail();
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

        $savedMessage = DB::transaction(function () use ($account, $message, $value, $providerId, $phone, $event) {
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

            $savedMessage = WhatsappConversationMessage::withoutGlobalScopes()->firstOrCreate(
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
            return $savedMessage;
        });

        if ($savedMessage && $savedMessage->message_type === 'text' && $savedMessage->body) {
            ProcessWhatsAppTenantAgent::dispatch((int) $savedMessage->id)->afterCommit();
        }
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
