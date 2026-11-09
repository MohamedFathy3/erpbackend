<?php

namespace App\Http\Controllers;

use App\Models\WhatsappAccount;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WhatsappAccountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $account = $this->accountForTenant($request);

        return response()->json(['data' => [
            'connected' => (bool) ($account?->is_active && $account?->access_token),
            'display_phone_number' => $account?->display_phone_number,
            'verified_name' => $account?->verified_name,
            'phone_number_id' => $account?->phone_number_id,
            'waba_id' => $account?->business_account_id,
            'status' => $account?->connection_status,
            'webhook_url' => $account ? url('/api/integrations/whatsapp/webhook') : null,
            'setup_method' => $account?->setup_method,
            'last_error' => $account?->connection_status === 'setup_failed' ? $account->last_error : null,
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone_number_id' => ['required', 'string', 'max:64'],
            'waba_id' => ['required', 'string', 'max:64'],
            'display_phone_number' => ['required', 'string', 'max:32'],
            'verified_name' => ['required', 'string', 'max:255'],
            'access_token' => ['nullable', 'string', 'min:20', 'max:4096'],
            'app_secret' => ['nullable', 'string', 'min:20', 'max:512'],
            'webhook_verify_token' => ['nullable', 'string', 'min:16', 'max:256'],
        ]);

        $account = $this->accountForTenant($request);
        if (!$account && empty($data['access_token'])) {
            throw ValidationException::withMessages(['access_token' => 'أدخل Access Token عند إنشاء أول اتصال.']);
        }
        if (!$account && empty($data['webhook_verify_token'])) {
            throw ValidationException::withMessages(['webhook_verify_token' => 'أدخل Verify Token لاستخدامه عند تسجيل الـ Webhook في Meta.']);
        }

        $tenantId = $this->tenantId($request);
        $account ??= new WhatsappAccount(['tenant_id' => $tenantId]);
        $account->tenant_id = $tenantId;
        $account->phone_number_id = $data['phone_number_id'];
        $account->business_account_id = $data['waba_id'];
        $account->display_phone_number = $data['display_phone_number'];
        $account->verified_name = $data['verified_name'];
        $account->is_active = true;
        $account->webhook_key ??= (string) Str::uuid();
        if (!empty($data['access_token'])) {
            $account->access_token = $data['access_token'];
        }
        if (!empty($data['app_secret'])) {
            $account->app_secret = $data['app_secret'];
        }
        if (!empty($data['webhook_verify_token'])) {
            $account->webhook_verify_token = $data['webhook_verify_token'];
        }
        $account->connection_status = 'configured';
        $account->last_error = null;
        $account->save();

        return response()->json(['data' => [
            'connected' => true,
            'display_phone_number' => $account->display_phone_number,
            'verified_name' => $account->verified_name,
            'phone_number_id' => $account->phone_number_id,
            'waba_id' => $account->business_account_id,
            'status' => $account->connection_status,
            'webhook_url' => url('/api/integrations/whatsapp/webhook/' . $account->webhook_key),
        ]]);
    }

    public function test(Request $request, WhatsAppCloudApiService $whatsapp): JsonResponse
    {
        $account = $this->accountForTenant($request);
        abort_unless($account && $account->is_active, 409, 'احفظ إعدادات اتصال WhatsApp أولاً.');

        try {
            $meta = $whatsapp->testConnection($account);
            $account->forceFill([
                'connection_status' => 'connected',
                'last_tested_at' => now(),
                'last_error' => null,
                'display_phone_number' => $meta['display_phone_number'] ?? $account->display_phone_number,
                'verified_name' => $meta['verified_name'] ?? $account->verified_name,
            ])->save();

            return response()->json(['data' => [
                'connected' => true,
                'phone_number_id' => $meta['id'] ?? $account->phone_number_id,
                'display_phone_number' => $account->display_phone_number,
                'verified_name' => $account->verified_name,
                'quality_rating' => $meta['quality_rating'] ?? null,
            ]]);
        } catch (\Throwable $exception) {
            $account->forceFill([
                'connection_status' => 'error',
                'last_tested_at' => now(),
                'last_error' => Str::limit($exception->getMessage(), 1000),
            ])->save();
            report($exception);
            return response()->json(['message' => 'فشل اختبار الاتصال بـ Meta. راجع صلاحية Access Token ومعرّف الرقم.'], 422);
        }
    }

    private function accountForTenant(Request $request): ?WhatsappAccount
    {
        $tenantId = $this->tenantId($request);
        return WhatsappAccount::query()->where('tenant_id', $tenantId)->first();
    }

    private function tenantId(Request $request): int
    {
        $tenantId = (int) ($request->user()?->tenant_id ?: (app()->bound('currentTenantId') ? app('currentTenantId') : 0));
        abort_if($tenantId < 1, 403, 'لم يتم تحديد شركة نشطة لهذا المستخدم.');
        return $tenantId;
    }
}
