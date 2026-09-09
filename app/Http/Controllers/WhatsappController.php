<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\WhatsappMessage;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class WhatsappController extends Controller
{
    public function __construct(private readonly WhatsAppCloudApiService $whatsapp) {}

    public function messages(Request $request)
    {
        $query = WhatsappMessage::query()->with('customer')->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->integer('customer_id'));
        }

        return response()->json(['data' => $query->paginate($request->integer('per_page', 25))]);
    }

    public function sendToCustomer(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'type' => 'required|in:text,template',
            'body' => 'required_if:type,text|string|max:4096',
            'template_name' => 'required_if:type,template|string|max:512',
            'language_code' => 'required_if:type,template|string|max:35',
            'components' => 'nullable|array',
            'phone' => 'nullable|string|max:30',
        ]);

        $to = $this->normalizedPhone($data['phone'] ?? $customer->phone);
        if (!$to) {
            throw ValidationException::withMessages(['phone' => 'Customer phone must be a valid E.164 number.']);
        }

        if ($data['type'] === 'text' && (!$customer->whatsapp_last_inbound_at || $customer->whatsapp_last_inbound_at->lt(now()->subHours(24)))) {
            throw ValidationException::withMessages(['type' => 'Free-form text is allowed only during an open 24-hour customer service window. Use an approved template outside that window.']);
        }

        $message = WhatsappMessage::create([
            'customer_id' => $customer->id,
            'to_phone' => $to,
            'type' => $data['type'],
            'template_name' => $data['template_name'] ?? null,
            'status' => 'pending',
            'payload' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);

        try {
            $response = $data['type'] === 'text'
                ? $this->whatsapp->sendText($to, $data['body'])
                : $this->whatsapp->sendTemplate($to, $data['template_name'], $data['language_code'], $data['components'] ?? []);

            $providerResponse = $response->json() ?: ['http_status' => $response->status(), 'body' => $response->body()];
            $message->update([
                'status' => $response->successful() ? 'sent' : 'failed',
                'provider_message_id' => data_get($response->json(), 'messages.0.id'),
                'meta_response' => $providerResponse,
                'sent_at' => $response->successful() ? now() : null,
                'failed_at' => $response->successful() ? null : now(),
                'error_code' => data_get($response->json(), 'error.code'),
                'error_message' => data_get($response->json(), 'error.message') ?: (!$response->successful() ? "Meta HTTP {$response->status()}: {$response->body()}" : null),
            ]);
        } catch (\Throwable $e) {
            $message->update(['status' => 'failed', 'failed_at' => now(), 'error_message' => $e->getMessage()]);
            throw $e;
        }

        $freshMessage = $message->fresh();
        return response()->json(['data' => $freshMessage, 'message' => $freshMessage->error_message], $message->status === 'sent' ? 201 : 422);
    }

    public function verifyNumber(Request $request, Customer $customer)
    {
        $phone = $this->normalizedPhone($customer->phone);
        $result = [
            'phone' => $customer->phone,
            'normalized_phone' => $phone,
            'format_valid' => $phone !== null,
            'availability_checked' => false,
            'message' => 'Meta does not provide a public general-purpose WhatsApp number lookup. Availability can only be learned from an approved outbound message result, with appropriate customer opt-in.',
        ];

        if (!$phone) {
            return response()->json(['data' => $result], 422);
        }

        if ($request->filled('template_name')) {
            $data = $request->validate(['template_name' => 'string|max:512', 'language_code' => 'required|string|max:35', 'components' => 'nullable|array']);
            $message = WhatsappMessage::create([
                'customer_id' => $customer->id,
                'to_phone' => $phone,
                'type' => 'template',
                'template_name' => $data['template_name'],
                'status' => 'pending',
                'payload' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            $response = $this->whatsapp->sendTemplate($phone, $data['template_name'], $data['language_code'], $data['components'] ?? []);
            $result['availability_checked'] = true;
            $result['send_accepted'] = $response->successful();
            $result['provider_response'] = $response->json();
            $message->update([
                'status' => $response->successful() ? 'sent' : 'failed',
                'provider_message_id' => data_get($response->json(), 'messages.0.id'),
                'meta_response' => $response->json(),
                'sent_at' => $response->successful() ? now() : null,
                'failed_at' => $response->successful() ? null : now(),
                'error_code' => data_get($response->json(), 'error.code'),
                'error_message' => data_get($response->json(), 'error.message'),
            ]);
        }

        return response()->json(['data' => $result]);
    }

    public function webhook(Request $request)
    {
        $appSecret = config('whatsapp.app_secret');
        if ($appSecret) {
            $signature = (string) $request->header('X-Hub-Signature-256');
            $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $appSecret);
            abort_unless(hash_equals($expected, $signature), 401);
        }

        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach (data_get($change, 'value.statuses', []) as $status) {
                    $message = WhatsappMessage::withoutGlobalScopes()->where('provider_message_id', $status['id'] ?? null)->first();
                    if (!$message) continue;
                    $statusName = $status['status'] ?? 'failed';
                    $message->update([
                        'status' => in_array($statusName, ['sent', 'delivered', 'read', 'failed'], true) ? $statusName : $message->status,
                        'error_code' => data_get($status, 'errors.0.code'),
                        'error_message' => data_get($status, 'errors.0.title'),
                        $statusName . '_at' => isset($status['timestamp']) ? Carbon::createFromTimestamp((int) $status['timestamp']) : now(),
                        'meta_response' => $status,
                    ]);
                }

                foreach (data_get($change, 'value.messages', []) as $incoming) {
                    $phone = $this->normalizedPhone($incoming['from'] ?? null);
                    if ($phone) {
                        Customer::withoutGlobalScopes()->where('phone', $phone)->update(['whatsapp_last_inbound_at' => now()]);
                    }
                }
            }
        }

        return response()->json(['status' => 'ok']);
    }

    public function verifyWebhook(Request $request)
    {
        if ($request->query('hub_verify_token') !== config('whatsapp.webhook_verify_token')) {
            abort(403);
        }
        return response($request->query('hub_challenge'), 200);
    }

    private function normalizedPhone(?string $phone): ?string
    {
        if (!$phone) return null;
        $phone = preg_replace('/[\s().-]+/', '', trim($phone));
        return preg_match('/^\+[1-9]\d{7,14}$/', $phone) ? $phone : null;
    }
}
