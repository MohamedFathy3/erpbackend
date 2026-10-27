<?php

namespace App\Http\Controllers;

use App\Models\BiometricDevice;
use App\Models\BiometricLog;
use App\Models\Employee;
use App\Services\BiometricAgentAttendanceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives the HTTP ADMS/PUSH protocol emitted by ZKTeco terminals.
 * These routes intentionally do not use Sanctum: the terminal cannot send
 * an ERP user token. Device identity and tenant are established from the
 * registered serial number, so all SaaS tenants can use one endpoint.
 */
class AdmsController extends Controller
{
    public function registry(Request $request): Response
    {
        $device = $this->resolveDevice($request, true);
        $device->forceFill([
            'last_seen_at' => now(),
            'adms_registered_at' => $device->adms_registered_at ?: now(),
            'last_error' => null,
        ])->save();

        return response("Registry=ok\r\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function cdata(Request $request): Response
    {
        $device = $this->resolveDevice($request, true);
        $table = strtoupper((string) $request->query('table', 'ATTLOG'));
        $body = (string) $request->getContent();

        if ($request->isMethod('GET') || $body === '') {
            $this->touch($device);
            return response("OK\r\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $imported = 0;
        if ($table === 'ATTLOG' || str_contains(strtoupper($body), 'ATTLOG')) {
            foreach ($this->parseAttendanceRows($body) as $row) {
                if ($this->storeAttendance($device, $row)) {
                    $imported++;
                }
            }
        }

        $this->touch($device);
        return response("OK\r\n", 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-ADMS-Imported' => (string) $imported,
        ]);
    }

    public function getrequest(Request $request): Response
    {
        $device = $this->resolveDevice($request, false);
        if ($device) {
            $this->touch($device);
        }

        // A valid empty command response tells the terminal that the server
        // is online and that no remote command is waiting.
        return response("OK\r\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function devicecmd(Request $request): Response
    {
        $device = $this->resolveDevice($request, false);
        if ($device) {
            $this->touch($device);
        }
        return response("OK\r\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /** @return array<int, array{user_id:string,recorded_at:Carbon,state:?int,punch:?int,verify_mode:?int,work_code:?string,raw:string}> */
    private function parseAttendanceRows(string $body): array
    {
        $rows = [];
        $body = str_replace(["\r\n", "\r"], "\n", trim($body));
        foreach (preg_split('/\n+/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with(strtoupper($line), 'ATTLOG')) {
                continue;
            }
            $parts = preg_split('/[\t,]+/', $line);
            if (!$parts || count($parts) < 2) {
                continue;
            }
            $userId = trim((string) ($parts[0] ?? ''));
            $timestamp = trim((string) ($parts[1] ?? ''));
            if ($userId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $timestamp)) {
                continue;
            }
            try {
                $rows[] = [
                    'user_id' => $userId,
                    'recorded_at' => Carbon::parse($timestamp),
                    'state' => isset($parts[2]) && is_numeric($parts[2]) ? (int) $parts[2] : null,
                    'verify_mode' => isset($parts[3]) && is_numeric($parts[3]) ? (int) $parts[3] : null,
                    'work_code' => isset($parts[4]) ? trim((string) $parts[4]) : null,
                    'punch' => isset($parts[2]) && is_numeric($parts[2]) ? (int) $parts[2] : null,
                    'raw' => $line,
                ];
            } catch (\Throwable) {
                // Ignore malformed rows but acknowledge the request so the
                // terminal does not retry an invalid packet forever.
            }
        }
        return $rows;
    }

    private function storeAttendance(BiometricDevice $device, array $row): bool
    {
        $tenantId = (int) $device->tenant_id;
        $employee = Employee::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($row): void {
                $query->where('biometric_user_id', $row['user_id'])
                    ->orWhere('employee_code', $row['user_id']);
            })
            ->first();

        $log = DB::transaction(function () use ($device, $row, $employee, $tenantId): BiometricLog {
            $existing = BiometricLog::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('device_id', $device->id)
                ->where('device_user_id', $row['user_id'])
                ->where('recorded_at', $row['recorded_at']->toDateTimeString())
                ->first();
            if ($existing) {
                return $existing;
            }
            return BiometricLog::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'device_id' => $device->id,
                'employee_id' => $employee?->id,
                'device_user_id' => $row['user_id'],
                'state' => $row['state'],
                'recorded_at' => $row['recorded_at'],
                'payload' => [
                    'protocol' => 'adms',
                    'raw' => $row['raw'],
                    'verify_mode' => $row['verify_mode'],
                    'punch' => $row['punch'],
                    'work_code' => $row['work_code'],
                ],
            ]);
        });

        if ($log->wasRecentlyCreated && $employee) {
            app(BiometricAgentAttendanceService::class)->refreshEmployeeDay($employee, $row['recorded_at']);
        }
        return $log->wasRecentlyCreated;
    }

    private function resolveDevice(Request $request, bool $create): ?BiometricDevice
    {
        $serial = trim((string) ($request->query('SN') ?: $request->query('sn') ?: $request->input('SN')));
        if ($serial === '' || !preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $serial)) {
            abort(422, 'The ZKTeco serial number (SN) is required.');
        }
        $device = BiometricDevice::withoutGlobalScopes()
            ->where('serial_number', $serial)
            ->first();
        if (!$device) {
            abort(404, 'This ZKTeco serial number is not registered. Add it from the ERP biometric settings first.');
        }
        if (!$device->is_active) {
            abort(403, 'This biometric device is inactive.');
        }
        app()->instance('currentTenantId', (int) $device->tenant_id);
        return $device;
    }

    private function touch(BiometricDevice $device): void
    {
        $device->forceFill(['last_seen_at' => now(), 'last_error' => null])->save();
    }
}
