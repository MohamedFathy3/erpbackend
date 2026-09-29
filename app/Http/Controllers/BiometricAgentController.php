<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\BiometricAgent;
use App\Models\BiometricAgentPairingCode;
use App\Models\BiometricDevice;
use App\Models\BiometricLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\BiometricAgentAttendanceService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BiometricAgentController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = $this->tenantId($request);
        $this->authorizeHrManager($request);

        $agents = BiometricAgent::query()
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->get()
            ->map(fn (BiometricAgent $agent): array => [
                'id' => (string) $agent->id,
                'name' => $agent->name,
                'status' => $agent->revoked_at
                    ? 'revoked'
                    : ($agent->last_seen_at && $agent->last_seen_at->gt(now()->subSeconds(90)) ? $agent->status : 'offline'),
                'last_seen_at' => $agent->last_seen_at?->toIso8601String(),
                'version' => $agent->agent_version,
                'devices_count' => (int) $agent->devices_count,
                'pending_events' => (int) $agent->pending_events,
            ])
            ->values();

        return response()->json(['status' => true, 'data' => $agents]);
    }

    public function createPairingCode(Request $request)
    {
        $tenantId = $this->tenantId($request);
        $this->authorizeHrManager($request);
        $data = $request->validate(['name' => 'required|string|max:120']);
        $code = Str::random(64);
        $expiresAt = now()->addMinutes(10);

        BiometricAgentPairingCode::query()->create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'code_hash' => hash('sha256', $code),
            'created_by_type' => get_class($request->user()),
            'created_by_id' => $request->user()->getKey(),
            'expires_at' => $expiresAt,
        ]);

        return response()->json([
            'status' => true,
            'data' => ['code' => $code, 'expires_at' => $expiresAt->toIso8601String()],
        ], 201);
    }

    public function pair(Request $request)
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate(['code' => 'required|string|max:128']);
        $codeHash = hash('sha256', trim($data['code']));

        $paired = DB::transaction(function () use ($tenantId, $codeHash): array {
            $pairingCode = BiometricAgentPairingCode::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('code_hash', $codeHash)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (!$pairingCode) {
                throw new HttpException(422, 'Pairing code is invalid, expired, or already used.');
            }

            $creatorModel = match ($pairingCode->created_by_type) {
                Admin::class => Admin::class,
                Employee::class => Employee::class,
                User::class => User::class,
                default => null,
            };
            if (!$creatorModel) {
                throw new HttpException(422, 'The pairing code owner is no longer available.');
            }

            $creator = $creatorModel::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->find($pairingCode->created_by_id);
            if (!$creator || ($creator->super_admin ?? false)) {
                throw new HttpException(422, 'The pairing code owner is no longer available.');
            }

            $pairingCode->forceFill(['used_at' => now()])->save();
            $agent = BiometricAgent::query()->create([
                'tenant_id' => $tenantId,
                'name' => $pairingCode->name,
                'created_by_type' => $pairingCode->created_by_type,
                'created_by_id' => $pairingCode->created_by_id,
                'status' => 'offline',
            ]);
            $agentToken = Str::random(64);
            $agent->forceFill(['token_hash' => hash('sha256', $agentToken)])->save();

            return ['agent_id' => (string) $agent->id, 'agent_token' => $agentToken];
        });

        return response()->json(['status' => true, 'data' => $paired], 201);
    }

    public function revoke(Request $request, int $agent)
    {
        $tenantId = $this->tenantId($request);
        $this->authorizeHrManager($request);
        $record = BiometricAgent::query()->where('tenant_id', $tenantId)->findOrFail($agent);

        $record->forceFill([
            'token_hash' => null,
            'status' => 'revoked',
            'revoked_at' => now(),
        ])->save();

        return response()->json(['status' => true, 'message' => 'Agent access revoked.']);
    }

    public function devices(Request $request)
    {
        $agent = $this->agentForRequest($request);
        $devices = BiometricDevice::query()
            ->where('tenant_id', $agent->tenant_id)
            ->where('is_active', true)
            ->get(['id', 'name', 'ip_address', 'port', 'protocol', 'device_password', 'is_active'])
            ->map(fn (BiometricDevice $device): array => [
                'id' => (string) $device->id,
                'name' => $device->name,
                'ip_address' => $device->ip_address,
                'port' => (int) $device->port,
                'protocol' => $device->protocol,
                'password' => (int) $device->device_password,
                'is_active' => (bool) $device->is_active,
            ])
            ->values();

        return response()->json(['status' => true, 'data' => ['devices' => $devices]]);
    }

    public function ingestEvents(Request $request, BiometricAgentAttendanceService $attendanceService)
    {
        $agent = $this->agentForRequest($request);
        $data = $request->validate([
            'events' => 'required|array|min:1|max:250',
            'events.*.event_key' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
            'events.*.device_id' => 'required|integer|min:1',
            'events.*.device_user_id' => 'required|string|max:32',
            'events.*.timestamp' => 'required|date',
            'events.*.status' => 'nullable|integer|min:0|max:255',
            'events.*.punch' => 'nullable|integer|min:0|max:255',
            'events.*.device_uid' => 'nullable|integer|min:0|max:65535',
        ]);

        $tenantId = (int) $agent->tenant_id;
        $devices = [];
        $accepted = [];
        $refreshDays = [];

        foreach ($data['events'] as $event) {
            $deviceId = (int) $event['device_id'];
            $device = $devices[$deviceId] ??= BiometricDevice::query()
                ->where('tenant_id', $tenantId)
                ->find($deviceId);
            if (!$device) {
                abort(422, 'An event references a device outside this tenant.');
            }

            $recordedAt = Carbon::parse($event['timestamp']);
            $deviceUserId = (string) $event['device_user_id'];
            $employee = Employee::query()
                ->where('tenant_id', $tenantId)
                ->where(function ($query) use ($deviceUserId): void {
                    $query->where('biometric_user_id', $deviceUserId)
                        ->orWhere('employee_code', $deviceUserId);
                })
                ->first();

            try {
                $log = DB::transaction(function () use ($event, $device, $deviceUserId, $recordedAt, $employee, $tenantId): BiometricLog {
                    $log = BiometricLog::query()
                        ->where('tenant_id', $tenantId)
                        ->where('device_id', $device->id)
                        ->where('device_user_id', $deviceUserId)
                        ->where('recorded_at', $recordedAt->toDateTimeString())
                        ->lockForUpdate()
                        ->first();

                    if (!$log) {
                        $log = BiometricLog::query()->create([
                            'tenant_id' => $tenantId,
                            'device_id' => $device->id,
                            'employee_id' => $employee?->id,
                            'device_user_id' => $deviceUserId,
                            'state' => $event['status'] ?? null,
                            'recorded_at' => $recordedAt,
                            'agent_event_key' => strtolower($event['event_key']),
                            'payload' => [
                                'user_id' => $deviceUserId,
                                'record_time' => $recordedAt->toIso8601String(),
                                'state' => $event['status'] ?? null,
                                'punch' => $event['punch'] ?? null,
                                'device_uid' => $event['device_uid'] ?? null,
                            ],
                        ]);
                    } elseif (!$log->agent_event_key) {
                        $log->forceFill(['agent_event_key' => strtolower($event['event_key'])])->save();
                    }

                    return $log;
                });
            } catch (QueryException $exception) {
                // Concurrent retries can race on either the natural device-log key or event-key index.
                $log = BiometricLog::query()
                    ->where('tenant_id', $tenantId)
                    ->where(function ($query) use ($event, $device, $deviceUserId, $recordedAt): void {
                        $query->where(function ($byEventKey) use ($event, $device): void {
                            $byEventKey->where('agent_event_key', strtolower($event['event_key']))
                                ->where('device_id', $device->id);
                        })
                            ->orWhere(function ($natural) use ($device, $deviceUserId, $recordedAt): void {
                                $natural->where('device_id', $device->id)
                                    ->where('device_user_id', $deviceUserId)
                                    ->where('recorded_at', $recordedAt->toDateTimeString());
                            });
                    })
                    ->first();
                if (!$log) {
                    throw $exception;
                }
            }

            $accepted[] = strtolower($event['event_key']);
            if ($log->employee_id) {
                $refreshDays[$log->employee_id . ':' . $recordedAt->toDateString()] = [$log->employee_id, $recordedAt->copy()];
            }
            $device->forceFill(['last_synced_at' => now(), 'last_error' => null])->save();
        }

        foreach ($refreshDays as [$employeeId, $date]) {
            $employee = Employee::query()->where('tenant_id', $tenantId)->find($employeeId);
            if ($employee) {
                $attendanceService->refreshEmployeeDay($employee, $date);
            }
        }

        return response()->json(['status' => true, 'data' => ['accepted_event_keys' => array_values(array_unique($accepted))]]);
    }

    public function heartbeat(Request $request)
    {
        $agent = $this->agentForRequest($request);
        $data = $request->validate([
            'agent_id' => 'sometimes|string|max:32',
            'agent_version' => 'nullable|string|max:32',
            'status' => 'required|in:online,degraded',
            'devices_count' => 'required|integer|min:0|max:1000',
            'pending_events' => 'required|integer|min:0|max:10000000',
            'errors' => 'sometimes|array|max:50',
            'errors.*.device_id' => 'nullable|string|max:64',
            'errors.*.message' => 'nullable|string|max:500',
        ]);
        if (isset($data['agent_id']) && (string) $agent->id !== (string) $data['agent_id']) {
            abort(403, 'Agent identity does not match its bearer token.');
        }

        $agent->forceFill([
            'status' => $data['status'],
            'last_seen_at' => now(),
            'agent_version' => $data['agent_version'] ?? null,
            'devices_count' => $data['devices_count'],
            'pending_events' => $data['pending_events'],
            'last_error' => empty($data['errors']) ? null : json_encode($data['errors'], JSON_UNESCAPED_UNICODE),
        ])->save();

        return response()->json(['status' => true, 'data' => ['accepted' => true]]);
    }

    private function agentForRequest(Request $request): BiometricAgent
    {
        $agentId = $request->attributes->get('biometricAgentId');
        if (!$agentId) {
            abort(403, 'The token is not scoped to a biometric agent.');
        }

        $tenantId = $this->tenantId($request);
        return BiometricAgent::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('revoked_at')
            ->findOrFail($agentId);
    }

    private function tenantId(Request $request): int
    {
        $user = $request->user();
        if ($user && ($user->super_admin ?? false)) {
            abort(403, 'A tenant admin account is required for biometric agents.');
        }
        $tenantId = $user?->tenant_id ?: (app()->bound('currentTenantId') ? app('currentTenantId') : null);
        if (!$tenantId) {
            abort(403, 'A tenant workspace is required.');
        }
        if ($user && $user->tenant_id && (int) $user->tenant_id !== (int) $tenantId) {
            abort(403, 'Tenant mismatch.');
        }
        return (int) $tenantId;
    }

    private function authorizeHrManager(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && method_exists($user, 'hasPermission') && $user->hasPermission('hr.view'), 403);
    }
}
