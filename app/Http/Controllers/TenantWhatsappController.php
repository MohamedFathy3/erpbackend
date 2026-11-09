<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\WhatsappAccount;
use App\Models\WhatsappMessage;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TenantWhatsappController extends Controller
{
    public function messages(Request $request): JsonResponse
    {
        $query = WhatsappMessage::query()->with('customer')->latest();
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        return response()->json(['data' => $query->paginate(min(max($request->integer('per_page', 25), 1), 100))]);
    }

    public function templates(Request $request, WhatsAppCloudApiService $whatsapp): JsonResponse
    {
        $account = $this->account($request);
        return response()->json(['data' => $whatsapp->templatesFor($account)]);
    }

    public function sendToCustomer(Request $request, Customer $customer, WhatsAppCloudApiService $whatsapp): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:text,template'],
            'body' => ['required_if:type,text', 'string', 'max:4096'],
            'template_name' => ['required_if:type,template', 'string', 'max:512'],
            'language_code' => ['required_if:type,template', 'string', 'max:35'],
            'components' => ['nullable', 'array'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);
        $account = $this->account($request);
        $phone = $this->normalizePhone($data['phone'] ?? $customer->phone);
        if (!$phone) {
            throw ValidationException::withMessages(['phone' => 'يجب إدخال رقم واتساب بصيغة دولية صحيحة.']);
        }
        if ($data['type'] === 'text' && (!$customer->whatsapp_last_inbound_at || $customer->whatsapp_last_inbound_at->lt(now()->subHours(24)))) {
            throw ValidationException::withMessages(['type' => 'الرسائل النصية الحرة مسموحة خلال نافذة 24 ساعة فقط. استخدم قالبًا معتمدًا خارجها.']);
        }

        $message = WhatsappMessage::create([
            'tenant_id' => $account->tenant_id,
            'customer_id' => $customer->id,
            'to_phone' => $phone,
            'type' => $data['type'],
            'template_name' => $data['template_name'] ?? null,
            'status' => 'pending',
            'payload' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        try {
            $response = $data['type'] === 'text'
                ? $whatsapp->sendTextFor($account, $phone, $data['body'])
                : $whatsapp->sendTemplateFor($account, $phone, $data['template_name'], $data['language_code'], $data['components'] ?? []);
            $provider = $response->json() ?: ['http_status' => $response->status()];
            $message->forceFill([
                'status' => $response->successful() ? 'sent' : 'failed',
                'provider_message_id' => data_get($provider, 'messages.0.id'),
                'meta_response' => $provider,
                'sent_at' => $response->successful() ? now() : null,
                'failed_at' => $response->successful() ? null : now(),
                'error_code' => data_get($provider, 'error.code'),
                'error_message' => data_get($provider, 'error.message') ?: (!$response->successful() ? 'Meta request failed with HTTP ' . $response->status() : null),
            ])->save();
        } catch (\Throwable $exception) {
            $message->forceFill(['status' => 'failed', 'failed_at' => now(), 'error_message' => $exception->getMessage()])->save();
            throw $exception;
        }

        return response()->json(['data' => $message->fresh('customer'), 'message' => $message->error_message], $message->status === 'sent' ? 201 : 422);
    }

    private function account(Request $request): WhatsappAccount
    {
        $tenantId = (int) ($request->user()?->tenant_id ?: (app()->bound('currentTenantId') ? app('currentTenantId') : 0));
        abort_if($tenantId < 1, 403, 'لم يتم تحديد شركة نشطة لهذا المستخدم.');
        $account = WhatsappAccount::query()->where('tenant_id', $tenantId)->first();
        abort_if(!$account, 409, 'لم يتم إعداد WhatsApp لهذه الشركة. افتح وحدة WhatsApp ثم إعداد الربط، واحفظ بيانات الحساب أولاً.');
        abort_if(!$account->is_active, 409, 'اتصال WhatsApp لهذه الشركة غير مفعّل. فعّله من إعدادات WhatsApp.');
        return $account;
    }

    private function normalizePhone(?string $phone): ?string
    {
        if (!$phone) return null;
        $phone = preg_replace('/[\s().-]+/', '', trim($phone));
        return preg_match('/^\+[1-9]\d{7,14}$/', $phone) ? $phone : null;
    }
}
