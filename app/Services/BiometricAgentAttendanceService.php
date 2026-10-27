<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceRule;
use App\Models\BiometricLog;
use App\Models\Employee;
use Carbon\Carbon;

class BiometricAgentAttendanceService
{
    public function refreshEmployeeDay(Employee $employee, Carbon $date): void
    {
        $tenantId = (int) $employee->tenant_id;
        $shift = $employee->shift;
        $workDate = $date->copy();
        if ($shift && $shift->end_time <= $shift->start_time && $date->format('H:i:s') < $shift->end_time) {
            $workDate->subDay();
        }
        $windowStart = $workDate->copy()->startOfDay();
        $windowEnd = $workDate->copy()->endOfDay();
        if ($shift) {
            $windowEnd = Carbon::parse($workDate->toDateString() . ' ' . $shift->end_time);
            if ($shift->end_time <= $shift->start_time) {
                $windowEnd->addDay();
            }
        }
        $logs = BiometricLog::query()
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employee->id)
            ->whereBetween('recorded_at', [$windowStart, $windowEnd])
            ->orderBy('recorded_at')
            ->get();

        if ($logs->isEmpty()) {
            return;
        }

        $firstIn = $logs->first(fn (BiometricLog $log): bool => (int) $log->state === 0) ?? $logs->first();
        $lastOut = $logs->reverse()->first(fn (BiometricLog $log): bool => in_array((int) $log->state, [1, 5], true));
        if (!$lastOut && $logs->count() > 1) {
            $lastOut = $logs->last();
        }

        $checkIn = Carbon::parse($firstIn->recorded_at);
        $checkOut = $lastOut ? Carbon::parse($lastOut->recorded_at) : null;
        $rule = AttendanceRule::query()->where('tenant_id', $tenantId)->where('is_active', true)->latest('id')->first();
        $startTime = $shift?->start_time ?: ($rule?->work_start ?: '08:00');
        $grace = (int) ($shift?->grace_minutes ?? $rule?->grace_minutes ?? 15);
        $start = Carbon::parse($workDate->toDateString() . ' ' . $startTime);
        $lateMinutes = max(0, (int) $start->diffInMinutes($checkIn, false) - $grace);
        $workedMinutes = $checkOut ? (int) $checkIn->diffInMinutes($checkOut) : 0;

        Attendance::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'employee_id' => $employee->id, 'date' => $workDate->toDateString()],
            [
                'tenant_id' => $tenantId,
                'device_id' => $logs->last()->device_id,
                'check_in' => $checkIn->format('H:i:s'),
                'check_out' => $checkOut?->format('H:i:s'),
                'status' => $lateMinutes > 0 ? 'late' : 'present',
                'source' => 'biometric',
                'late_minutes' => $lateMinutes,
                'worked_minutes' => $workedMinutes,
            ],
        );
    }
}
