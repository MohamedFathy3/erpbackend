<?php
namespace App\Http\Controllers;

use App\Models\WhatsappAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WhatsappEmbeddedSignupController extends Controller
{
    public function config(): JsonResponse
    {
        return response()->json(['data' => [
            'enabled' => (bool) (config('whatsapp.meta_app_id') && config('whatsapp.embedded_signup_config_id') && config('whatsapp.meta_app_secret') && config('whatsapp.webhook_verify_token')),
            'app_id' => config('whatsapp.meta_app_id'),
            'config_id' => config('whatsapp.embedded_signup_config_id'),
            'graph_api_version' => config('whatsapp.graph_version', 'v27.0'),
        ]]);
    }

    public function complete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:4096'],
            'phone_number_id' => ['required', 'string', 'max:64'],
            'waba_id' => ['required', 'string', 'max:64'],
            'business_id' => ['nullable', 'string', 'max:64'],
            'event' => ['required', 'string', 'max:64'],
        ]);
        if ($data['event'] !== 'FINISH') {
            return response()->json(['message' => 'هذا النوع من إعداد WhatsApp غير مدعوم حاليًا. استخدم تدفق التسجيل القياسي FINISH؛ لم يتم ربط أي حساب.'], 422);
        }
        $appId = config('whatsapp.meta_app_id');
        $secret = config('whatsapp.meta_app_secret');
        abort_unless($appId && $secret && config('whatsapp.embedded_signup_config_id'), 503, 'إعداد Meta Embedded Signup غير مكتمل على الخادم.');
        $version = config('whatsapp.graph_version', 'v27.0');
        $base = "https://graph.facebook.com/{$version}";
        try {
            // Exchange immediately: signup codes are short-lived (approximately 30 seconds).
            $exchange = Http::acceptJson()->timeout(20)->get($base . '/oauth/access_token', [
                'client_id' => $appId, 'client_secret' => $secret, 'code' => $data['code'],
            ]);
            $exchangeBody = trim($exchange->body());
            $exchangeJson = $exchange->json();
            $token = is_array($exchangeJson) ? data_get($exchangeJson, 'access_token') : null;
            if (!$token && $exchange->successful()) {
                $decodedBody = json_decode($exchangeBody, true);
                $token = is_string($decodedBody) ? $decodedBody : (is_array($exchangeJson) ? null : $exchangeBody);
            }
            if (!$exchange->successful() || !is_string($token) || trim($token) === '') {
                return response()->json(['message' => 'انتهت صلاحية رمز Meta أو تعذر استبداله. أعد محاولة التسجيل من نافذة WhatsApp.'], 422);
            }
            $token = trim($token, "\"' \t\n\r\0\x0B");
            $phone = Http::withToken($token)->acceptJson()->timeout(20)->get($base . '/' . rawurlencode($data['phone_number_id']), [
                'fields' => 'id,display_phone_number,verified_name',
            ]);
            $verified = $phone->json();
            if (!$phone->successful() || (string) data_get($verified, 'id') !== $data['phone_number_id']) {
                return response()->json(['message' => 'لم تتطابق بيانات رقم الهاتف وحساب WhatsApp Business التي أكدتها Meta. لم يتم تفعيل الاتصال.'], 422);
            }
            $numberIsInWaba = false;
            $numberAlreadyConnected = false;
            $after = null;
            for ($page = 0; $page < 10; $page++) {
                $phoneListQuery = ['fields' => 'id,status', 'limit' => 100];
                if ($after) $phoneListQuery['after'] = $after;
                $phoneList = Http::withToken($token)->acceptJson()->timeout(20)->get($base . '/' . rawurlencode($data['waba_id']) . '/phone_numbers', $phoneListQuery);
                if (!$phoneList->successful()) break;
                $selectedPhone = collect($phoneList->json('data', []))->first(fn ($item) => (string) ($item['id'] ?? '') === $data['phone_number_id']);
                if ($selectedPhone) {
                    $numberIsInWaba = true;
                    $numberAlreadyConnected = strtoupper((string) ($selectedPhone['status'] ?? '')) === 'CONNECTED';
                    break;
                }
                $after = data_get($phoneList->json(), 'paging.cursors.after');
                if (!$after) break;
            }
            if (!$numberIsInWaba) {
                return response()->json(['message' => 'لم تتمكن Meta من تأكيد أن رقم الهاتف تابع لحساب WhatsApp المختار. أعد اختيار الرقم والحساب من نافذة Meta.'], 422);
            }
            $tenantId = (int) ($request->user()?->tenant_id ?: (app()->bound('currentTenantId') ? app('currentTenantId') : 0));
            abort_if($tenantId < 1, 403, 'لم يتم تحديد شركة نشطة لهذا المستخدم.');
            $account = WhatsappAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('phone_number_id', $data['phone_number_id'])->first();
            $pin = $account?->register_pin ?: (string) random_int(100000, 999999);
            $account ??= new WhatsappAccount(['tenant_id' => $tenantId]);
            $account->forceFill([
                'tenant_id' => $tenantId, 'phone_number_id' => $data['phone_number_id'],
                'business_account_id' => $data['waba_id'], 'display_phone_number' => data_get($verified, 'display_phone_number'),
                'verified_name' => data_get($verified, 'verified_name'), 'access_token' => $token,
                'register_pin' => $pin,
                'webhook_verify_token' => $account?->webhook_verify_token ?: Str::random(64),
                'setup_method' => 'embedded_signup', 'metadata' => [
                    'phone_number_id' => $data['phone_number_id'], 'waba_id' => $data['waba_id'],
                    'business_id' => $data['business_id'] ?? null,
                ], 'webhook_key' => $account->webhook_key ?: (string) Str::uuid(),
                'is_active' => false, 'connection_status' => 'pending', 'last_error' => null, 'deleted_at' => null,
            ])->save();
            $subscribe = Http::withToken($token)->acceptJson()->timeout(20)->post($base . '/' . rawurlencode($data['waba_id']) . '/subscribed_apps');
            if (!$subscribe->successful()) return $this->setupFailed($account, 'تعذر اشتراك تطبيق Meta في أحداث WhatsApp. تحقق من الصلاحيات وإعداد Webhooks ثم أعد المحاولة.', 422);
            if (!$numberAlreadyConnected) {
                $register = Http::withToken($token)->acceptJson()->timeout(20)->post($base . '/' . rawurlencode($data['phone_number_id']) . '/register', [
                    'messaging_product' => 'whatsapp', 'pin' => $pin,
                ]);
                if (!$register->successful()) return $this->setupFailed($account, 'تحقق Meta من الرقم لكن تعذر تسجيله في Cloud API. راجع حالة الرقم/متطلبات الترحيل ثم أعد المحاولة.', 422);
            }
            $account->forceFill(['is_active' => true, 'connection_status' => 'connected', 'last_error' => null, 'last_tested_at' => now()])->save();
            return response()->json(['data' => [
                'connected' => true, 'display_phone_number' => $account->display_phone_number,
                'verified_name' => $account->verified_name, 'phone_number_id' => $account->phone_number_id,
                'waba_id' => $account->business_account_id, 'status' => 'connected',
                'webhook_url' => url('/api/integrations/whatsapp/webhook'),
            ]]);
        } catch (ConnectionException) {
            return response()->json(['message' => 'تعذر الاتصال بـ Meta. رمز الإعداد صالح لفترة قصيرة؛ أعد بدء تدفق التسجيل.'], 502);
        }
    }

    private function setupFailed(WhatsappAccount $account, string $message, int $status): JsonResponse
    {
        $account->forceFill(['is_active' => false, 'connection_status' => 'setup_failed', 'last_error' => $message])->save();
        return response()->json(['message' => $message, 'data' => ['connected' => false, 'status' => 'setup_failed']], $status);
    }
}
