<?php
namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceRule;
use App\Models\BiometricDevice;
use App\Models\BiometricLog;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Mithun\PhpZkteco\Libs\ZKTeco;
use RuntimeException;

class BiometricAttendanceService
{
    public function sync(BiometricDevice $device, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $this->assertReachable($device, 1.5);
        $zk = new ZKTeco($device->ip_address, (int) $device->port, timeout: 3, password: (int) $device->device_password, protocol: $device->protocol);
        if (!$zk->connect()) throw new RuntimeException('تعذر الاتصال بجهاز البصمة. تحقق من IP والمنفذ والشبكة.');

        try {
            $users = collect($zk->getUsers());
            $employees = Employee::query()->where('is_active', true)->get()->keyBy(fn ($e) => (string) $e->biometric_user_id);
            $logs = collect($zk->getAttendances())
                ->filter(function (array $raw) use ($from, $to): bool {
                    if (!$from && !$to) return true;

                    $timestamp = $raw['record_time'] ?? $raw['timestamp'] ?? null;
                    if ($timestamp === null) return false;

                    $recordedAt = Carbon::parse($timestamp);
                    return (!$from || $recordedAt->greaterThanOrEqualTo($from->copy()->startOfDay()))
                        && (!$to || $recordedAt->lessThanOrEqualTo($to->copy()->endOfDay()));
                })
                ->values()
                ->all();
            $imported = 0;
            $unmatched = [];
            $storedLogs = [];
            foreach ($logs as $raw) {
                $userId = (string) ($raw['user_id'] ?? $raw['uid'] ?? '');
                $recordedAt = Carbon::parse($raw['record_time'] ?? $raw['timestamp'] ?? now());
                $employee = $employees->get($userId) ?: Employee::where('employee_code', $userId)->first();
                $log = BiometricLog::firstOrCreate([
                    'device_id' => $device->id, 'device_user_id' => $userId, 'recorded_at' => $recordedAt,
                ], [
                    'employee_id' => $employee?->id, 'state' => isset($raw['state']) ? (int) $raw['state'] : null,
                    'payload' => $raw,
                ]);
                $storedLogs[] = $log;
                if ($log->wasRecentlyCreated) $imported++;
                if (!$employee) { $unmatched[$userId] = true; continue; }
            }
            $this->rebuildAttendance($device, $storedLogs);
            $device->update(['last_synced_at' => now(), 'last_error' => null]);
            return [
                'imported' => $imported,
                'unmatched_user_ids' => array_keys($unmatched),
                'device_users' => $users->count(),
                'period_start' => $from?->toDateString(),
                'period_end' => $to?->toDateString(),
                'scanned' => count($logs),
            ];
        } catch (\Throwable $e) {
            $device->update(['last_error' => $e->getMessage()]);
            throw $e;
        } finally {
            $zk->disconnect();
        }
    }

    public function testConnection(BiometricDevice $device): array
    {
        try {
            $this->assertReachable($device, 1.5);
            $zk = new ZKTeco($device->ip_address, (int) $device->port, timeout: 3, password: (int) $device->device_password, protocol: $device->protocol);
            $connected = $zk->connect();
            if (!$connected) return ['connected' => false, 'message' => 'تعذر الاتصال بالجهاز خلال المهلة المحددة'];
            $result = ['connected' => true, 'device_name' => $zk->deviceName() ?: null, 'serial_number' => $zk->serialNumber() ?: null];
            $zk->disconnect();
            return $result;
        } catch (\Throwable $e) {
            return ['connected' => false, 'message' => 'السيرفر لا يستطيع الوصول إلى الجهاز: ' . $e->getMessage()];
        }
    }

    public function deviceUsers(BiometricDevice $device): array
    {
        $zk = $this->client($device, 15);
        if (!$zk->connect()) throw new RuntimeException('تعذر الاتصال بجهاز البصمة. تحقق من IP والمنفذ والشبكة.');
        try {
            return collect($zk->getUsers())->map(fn (array $user) => [
                'uid' => (int) ($user['uid'] ?? 0),
                'user_id' => (string) ($user['user_id'] ?? $user['uid'] ?? ''),
                'name' => (string) ($user['name'] ?? ''),
                'role' => (int) ($user['role'] ?? 0),
                'card_no' => (string) ($user['card_no'] ?? ''),
                'device_ip' => $device->ip_address,
                'employee' => Employee::where('biometric_user_id', (string) ($user['user_id'] ?? $user['uid'] ?? ''))->first(['id', 'name', 'employee_code']),
            ])->values()->all();
        } finally {
            $zk->disconnect();
        }
    }

    public function createDeviceUser(BiometricDevice $device, array $data): array
    {
        $zk = $this->client($device, 15);
        if (!$zk->connect()) throw new RuntimeException('تعذر الاتصال بجهاز البصمة.');
        try {
            $ok = $zk->setUser((int) $data['uid'], (string) $data['user_id'], (string) $data['name'], (string) ($data['password'] ?? ''), (int) ($data['role'] ?? 0), (int) ($data['card_no'] ?? 0));
            if (!$ok) throw new RuntimeException('رفض الجهاز إضافة المستخدم.');
        } finally {
            $zk->disconnect();
        }
        if (!empty($data['employee_id'])) {
            Employee::whereKey($data['employee_id'])->update(['biometric_user_id' => (string) $data['user_id']]);
        }
        return ['uid' => (int) $data['uid'], 'user_id' => (string) $data['user_id'], 'name' => (string) $data['name'], 'employee_id' => $data['employee_id'] ?? null];
    }

    private function client(BiometricDevice $device, int $timeout): ZKTeco
    {
        $this->assertReachable($device, 1.5);
        return new ZKTeco($device->ip_address, (int) $device->port, timeout: $timeout, password: (int) $device->device_password, protocol: $device->protocol);
    }

    private function assertReachable(BiometricDevice $device, float $timeout): void
    {
        if ($device->protocol !== 'tcp') return;
        $errorCode = 0;
        $errorMessage = '';
        $socket = @stream_socket_client("tcp://{$device->ip_address}:{$device->port}", $errorCode, $errorMessage, $timeout, STREAM_CLIENT_CONNECT);
        if (is_resource($socket)) fclose($socket);
        if (!$socket) {
            throw new RuntimeException("لا يمكن الوصول إلى {$device->ip_address}:{$device->port} من السيرفر. استخدم VPN أو Agent داخل شبكة الجهاز.");
        }
    }

    public function previewPayroll(Employee $employee, Carbon $from, Carbon $to, ?AttendanceRule $rule = null): array
    {
        $rule ??= AttendanceRule::where('is_active', true)->latest('id')->first();
        $records = Attendance::where('employee_id', $employee->id)->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get();
        $lateMinutes = (int) $records->sum('late_minutes');
        $absentDays = $records->where('status', 'absent')->count();
        $lateDeduction = $this->deduction($employee, $rule, $lateMinutes, $absentDays, 'late');
        $absenceDeduction = $this->deduction($employee, $rule, $lateMinutes, $absentDays, 'absence');
        return ['employee_id' => $employee->id, 'period_start' => $from->toDateString(), 'period_end' => $to->toDateString(), 'attendance_days' => $records->whereIn('status', ['present', 'late'])->count(), 'absent_days' => $absentDays, 'late_minutes' => $lateMinutes, 'late_deduction' => round($lateDeduction, 2), 'absence_deduction' => round($absenceDeduction, 2), 'total_attendance_deduction' => round($lateDeduction + $absenceDeduction, 2), 'base_salary' => (float) $employee->salary, 'expected_net_salary' => max(0, round((float) $employee->salary - $lateDeduction - $absenceDeduction, 2))];
    }

    private function rebuildAttendance(BiometricDevice $device, array $logs): void
    {
        $timezone = config('services.biometric_timezone', 'Africa/Cairo');
        $refreshDays = [];
        foreach ($logs as $log) {
            if (!$log instanceof BiometricLog || !$log->employee_id || !$log->created_at) continue;
            $receivedAt = $log->created_at->copy()->setTimezone($timezone);
            $refreshDays[$log->employee_id . ':' . $receivedAt->toDateString()] = [$log->employee_id, $receivedAt];
        }

        foreach ($refreshDays as [$employeeId, $receivedAt]) {
            $employee = Employee::query()->where('tenant_id', $device->tenant_id)->find($employeeId);
            if ($employee) {
                app(BiometricAgentAttendanceService::class)->refreshEmployeeDay($employee, $receivedAt);
            }
        }
    }

    private function deduction(Employee $employee, ?AttendanceRule $rule, int $lateMinutes, int $absentDays, string $kind): float
    {
        if (!$rule) return 0;
        $type = $kind === 'late' ? $rule->late_deduction_type : $rule->absence_deduction_type;
        $value = (float) ($kind === 'late' ? $rule->late_deduction_value : $rule->absence_deduction_value);
        return match ($type) {
            'fixed' => $value,
            'hourly' => round(($lateMinutes / 60) * $value, 2),
            'daily' => round($absentDays * $value, 2),
            'percent' => round((float) $employee->salary * $value / 100, 2),
            default => 0,
        };
    }
}
