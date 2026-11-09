<?php

namespace App\Http\Controllers;

use App\Models\WhatsappAccount;
use App\Models\WhatsappAppointmentRequest;
use App\Models\WhatsappAgentSetting;
use App\Models\WhatsappConversation;
use App\Models\WhatsappConversationMessage;
use App\Models\CalendarEvent;
use App\Models\GoogleConnection;
use App\Services\GoogleCalendarService;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsappAgentController extends Controller
{
    public function settings(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $setting = WhatsappAgentSetting::query()->firstOrCreate(
            ['tenant_id' => $account->tenant_id, 'whatsapp_account_id' => $account->id],
            ['is_enabled' => false, 'enabled_actions' => []]
        );

        return response()->json(['data' => $setting]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $data = $request->validate([
            'is_enabled' => ['required', 'boolean'],
            'agent_name' => ['required', 'string', 'max:120'],
            'timezone' => ['required', 'timezone'],
            'business_context' => ['nullable', 'string', 'max:12000'],
            'instructions' => ['nullable', 'string', 'max:8000'],
            'handoff_message' => ['required', 'string', 'max:500'],
            'enabled_actions' => ['nullable', 'array', 'max:10'],
            'enabled_actions.*' => ['string', 'in:order_status,appointment_request,human_handoff'],
        ]);

        if ($data['is_enabled']) {
            abort_unless($account->is_active, 409, 'يجب ربط رقم WhatsApp فعال قبل تشغيل الوكيل.');
            abort_unless(config('ai.gemini_key'), 503, 'خدمة الذكاء الاصطناعي غير مهيأة على الخادم.');
            abort_unless(trim((string) ($data['business_context'] ?? '')) !== '', 422, 'أضف معلومات الشركة التي سيعتمد عليها الوكيل قبل تشغيله.');
        }

        $setting = WhatsappAgentSetting::query()->updateOrCreate(
            ['tenant_id' => $account->tenant_id, 'whatsapp_account_id' => $account->id],
            [
                'is_enabled' => $data['is_enabled'],
                'agent_name' => $data['agent_name'],
                'timezone' => $data['timezone'],
                'business_context' => $data['business_context'] ?? null,
                'instructions' => $data['instructions'] ?? null,
                'handoff_message' => $data['handoff_message'],
                'enabled_actions' => array_values(array_unique($data['enabled_actions'] ?? [])),
            ]
        );

        return response()->json(['data' => $setting]);
    }

    public function conversations(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $query = WhatsappConversation::query()
            ->where('tenant_id', $account->tenant_id)
            ->where('whatsapp_account_id', $account->id)
            ->with(['messages' => fn ($messages) => $messages->latest()->limit(30)])
            ->latest('updated_at');

        if ($request->filled('status') && in_array($request->string('status')->toString(), ['bot_active', 'needs_human', 'resolved'], true)) {
            $query->where('status', $request->string('status'));
        }

        return response()->json(['data' => $query->paginate(min(max($request->integer('per_page', 25), 1), 100))]);
    }

    public function updateConversation(Request $request, WhatsappConversation $conversation): JsonResponse
    {
        $account = $this->account($request);
        abort_unless((int) $conversation->tenant_id === (int) $account->tenant_id
            && (int) $conversation->whatsapp_account_id === (int) $account->id, 404);
        $data = $request->validate(['status' => ['required', 'in:bot_active,needs_human,resolved']]);
        $conversation->forceFill(['status' => $data['status']])->save();

        return response()->json(['data' => $conversation->fresh()]);
    }

    public function sendReply(Request $request, WhatsappConversation $conversation, WhatsAppCloudApiService $whatsapp): JsonResponse
    {
        $account = $this->account($request);
        abort_unless((int) $conversation->tenant_id === (int) $account->tenant_id
            && (int) $conversation->whatsapp_account_id === (int) $account->id, 404);
        abort_unless($conversation->status === 'needs_human', 409, 'حوّل المحادثة إلى موظف قبل الرد اليدوي.');
        abort_unless($conversation->last_inbound_at && $conversation->last_inbound_at->gte(now()->subHours(24)), 422, 'انتهت نافذة خدمة WhatsApp؛ استخدم قالبًا معتمدًا لبدء محادثة جديدة.');
        $data = $request->validate(['body' => ['required', 'string', 'max:4096']]);
        $response = $whatsapp->sendTextFor($account, $conversation->contact_phone, $data['body']);
        if (!$response->successful()) {
            return response()->json(['message' => 'تعذر إرسال الرد عبر Meta. أعد المحاولة أو استخدم قالبًا معتمدًا.'], 422);
        }
        WhatsappConversationMessage::withoutGlobalScopes()->create([
            'tenant_id' => $account->tenant_id,
            'whatsapp_account_id' => $account->id,
            'conversation_id' => $conversation->id,
            'provider_message_id' => data_get($response->json(), 'messages.0.id'),
            'direction' => 'outbound',
            'message_type' => 'text',
            'body' => $data['body'],
            'payload' => ['source' => 'human_agent', 'provider_response' => $response->json()],
            'provider_timestamp' => now(),
        ]);
        return response()->json(['data' => ['sent' => true]]);
    }

    public function appointmentRequests(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $query = WhatsappAppointmentRequest::query()
            ->where('tenant_id', $account->tenant_id)
            ->where('whatsapp_account_id', $account->id)
            ->latest();
        if ($request->filled('status') && in_array($request->string('status')->toString(), ['pending', 'contacted', 'closed'], true)) {
            $query->where('status', $request->string('status'));
        }
        return response()->json(['data' => $query->paginate(min(max($request->integer('per_page', 25), 1), 100))]);
    }

    public function updateAppointmentRequest(Request $request, WhatsappAppointmentRequest $appointmentRequest, GoogleCalendarService $google): JsonResponse
    {
        $account = $this->account($request);
        abort_unless((int) $appointmentRequest->tenant_id === (int) $account->tenant_id
            && (int) $appointmentRequest->whatsapp_account_id === (int) $account->id, 404);
        $data = $request->validate([
            'status' => ['required', 'in:pending,contacted,closed,confirmed'],
            'resolution_note' => ['nullable', 'string', 'max:2000'],
            'starts_at' => ['required_if:status,confirmed', 'date_format:Y-m-d\\TH:i'],
            'ends_at' => ['required_if:status,confirmed', 'date_format:Y-m-d\\TH:i'],
        ]);
        $calendarEventId = $appointmentRequest->calendar_event_id;
        if ($data['status'] === 'confirmed' && !$calendarEventId) {
            $timezone = WhatsappAgentSetting::query()
                ->where('tenant_id', $account->tenant_id)
                ->where('whatsapp_account_id', $account->id)
                ->value('timezone') ?: 'UTC';
            $startsAt = \Illuminate\Support\Carbon::createFromFormat('Y-m-d\\TH:i', $data['starts_at'], $timezone);
            $endsAt = \Illuminate\Support\Carbon::createFromFormat('Y-m-d\\TH:i', $data['ends_at'], $timezone);
            abort_if(!$startsAt || !$endsAt || !$startsAt->isFuture(), 422, 'اختر وقت بداية مستقبليًا وصحيحًا.');
            abort_if(!$endsAt->gt($startsAt), 422, 'وقت نهاية الموعد يجب أن يأتي بعد بدايته.');
            abort_if($startsAt->diffInMinutes($endsAt) > 480, 422, 'مدة الموعد لا يمكن أن تتجاوز 8 ساعات.');
            $connection = GoogleConnection::query()
                ->where('tenant_id', $account->tenant_id)
                ->where('status', 'connected')
                ->first();
            abort_unless($connection, 409, 'اربط Google Calendar للشركة قبل تأكيد موعد.');
            $calendarId = 'primary';
            abort_if($google->hasConflict($connection, $calendarId, $startsAt->toIso8601String(), $endsAt->toIso8601String()), 409, 'الوقت يتعارض مع حدث موجود في تقويم Google. اختر وقتًا آخر.');
            $localConflict = CalendarEvent::query()
                ->where('tenant_id', $account->tenant_id)
                ->whereNotIn('status', ['cancelled', 'canceled', 'declined'])
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->exists();
            abort_if($localConflict, 409, 'الوقت يتعارض مع حدث مسجل في تقويم الشركة. اختر وقتًا آخر.');
            $created = $google->createEvent($connection, [
                'summary' => 'موعد WhatsApp - ' . ($appointmentRequest->service_summary ?: $appointmentRequest->contact_phone),
                'description' => implode("\n", array_filter([
                    'موعد تم تأكيده من طلب WhatsApp.',
                    'رقم العميل: ' . $appointmentRequest->contact_phone,
                    $appointmentRequest->service_summary ? 'الخدمة: ' . $appointmentRequest->service_summary : null,
                    $appointmentRequest->customer_details,
                ])),
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $endsAt->toIso8601String(),
                'calendar_id' => $calendarId,
                'time_zone' => $timezone,
            ]);
            $calendarEvent = CalendarEvent::query()->create([
                'tenant_id' => $account->tenant_id,
                'user_id' => $connection->user_id,
                'google_connection_id' => $connection->id,
                'google_event_id' => $created['id'],
                'calendar_id' => $calendarId,
                'summary' => 'موعد WhatsApp - ' . ($appointmentRequest->service_summary ?: $appointmentRequest->contact_phone),
                'description' => 'رقم العميل: ' . $appointmentRequest->contact_phone,
                'starts_at' => $startsAt->copy()->setTimezone(config('app.timezone', 'UTC')),
                'ends_at' => $endsAt->copy()->setTimezone(config('app.timezone', 'UTC')),
                'google_payload' => $created['payload'],
            ]);
            $calendarEventId = $calendarEvent->id;
            $appointmentRequest->forceFill([
                'scheduled_starts_at' => $startsAt,
                'scheduled_ends_at' => $endsAt,
                'calendar_event_id' => $calendarEventId,
            ])->save();
        }
        $appointmentRequest->forceFill([
            'status' => $data['status'],
            'resolved_by' => $data['status'] === 'pending' ? null : $request->user()?->id,
            'resolved_at' => $data['status'] === 'pending' ? null : now(),
            'resolution_note' => $data['resolution_note'] ?? null,
        ])->save();
        return response()->json(['data' => $appointmentRequest->fresh()]);
    }

    private function account(Request $request): WhatsappAccount
    {
        $tenantId = (int) ($request->user()?->tenant_id ?: (app()->bound('currentTenantId') ? app('currentTenantId') : 0));
        abort_if($tenantId < 1, 403, 'لم يتم تحديد الشركة الحالية.');
        return WhatsappAccount::query()->where('tenant_id', $tenantId)->firstOrFail();
    }
}
