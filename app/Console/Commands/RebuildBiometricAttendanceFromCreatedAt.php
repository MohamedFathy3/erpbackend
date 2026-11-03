<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\BiometricLog;
use App\Models\Employee;
use App\Services\BiometricAgentAttendanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class RebuildBiometricAttendanceFromCreatedAt extends Command
{
    protected $signature = 'biometric:rebuild-attendance-from-created-at {--apply : Replace stored biometric attendance rows using BiometricLog.created_at} {--chunk=1000 : Number of biometric logs to scan per batch}';

    protected $description = 'Rebuild linked biometric attendance history from server receipt timestamps across all tenants';

    public function handle(BiometricAgentAttendanceService $attendanceService): int
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        // This command intentionally spans all tenants and branches.
        app()->forgetInstance('currentTenantId');
        app()->forgetInstance('currentBranchId');

        $chunkSize = max(100, min(5000, (int) $this->option('chunk')));
        $timezone = config('services.biometric_timezone', 'Africa/Cairo');
        $refreshDays = [];
        $employees = [];
        $scannedLogs = 0;
        $skippedLogs = 0;

        BiometricLog::withoutGlobalScopes(['tenant', 'branch'])
            ->select(['id', 'tenant_id', 'employee_id', 'created_at'])
            ->whereNotNull('employee_id')
            ->whereNotNull('created_at')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($logs) use (&$refreshDays, &$employees, &$scannedLogs, &$skippedLogs, $timezone): void {
                $employeeIds = $logs->pluck('employee_id')->filter()->unique()->values();
                $employeesInChunk = Employee::withoutGlobalScopes(['tenant', 'branch'])
                    ->with('shift')
                    ->whereIn('id', $employeeIds)
                    ->get(['id', 'tenant_id', 'shift_id'])
                    ->keyBy('id');

                foreach ($logs as $log) {
                    $scannedLogs++;
                    $employee = $employeesInChunk->get($log->employee_id);
                    if (!$employee || (int) $employee->tenant_id !== (int) $log->tenant_id) {
                        $skippedLogs++;
                        continue;
                    }

                    $employees[$employee->id] = $employee;
                    $receivedAt = $log->created_at->copy()->setTimezone($timezone);
                    $workDate = $receivedAt->copy();
                    $shift = $employee->shift;
                    if ($shift && $shift->end_time <= $shift->start_time && $receivedAt->format('H:i:s') < $shift->end_time) {
                        $workDate->subDay();
                    }

                    $key = $this->attendanceKey((int) $log->tenant_id, (int) $employee->id, $workDate->toDateString());
                    $refreshDays[$key] ??= [
                        'tenant_id' => (int) $log->tenant_id,
                        'employee_id' => (int) $employee->id,
                        'work_date' => $workDate->toDateString(),
                        'received_at' => $receivedAt,
                    ];
                }
            });

        $existingBiometricRows = Attendance::withoutGlobalScopes(['tenant', 'branch'])
            ->where('source', 'biometric')
            ->count();
        $manualConflicts = $this->findManualConflicts($refreshDays);
        $unassignedLogs = BiometricLog::withoutGlobalScopes(['tenant', 'branch'])
            ->whereNull('employee_id')
            ->count();

        $this->newLine();
        $this->info('Historical biometric attendance rebuild preview (all tenants)');
        $this->line("Linked biometric logs scanned: {$scannedLogs}");
        $this->line('Employee/workday groups to rebuild: ' . count($refreshDays));
        $this->line("Existing biometric attendance rows: {$existingBiometricRows}");
        $this->line('Manual attendance conflicts to preserve: ' . count($manualConflicts));
        $this->line("Logs skipped because their employee is missing or belongs to another tenant: {$skippedLogs}");
        $this->line("Unassigned biometric logs (not converted to attendance): {$unassignedLogs}");

        if (!$this->option('apply')) {
            $this->warn('Dry run only; no attendance records were changed. Use --apply to rebuild after reviewing these counts.');
            return self::SUCCESS;
        }

        if (empty($refreshDays)) {
            $this->error('No linked biometric logs were found; no attendance records were changed.');
            return self::FAILURE;
        }

        if (!$this->confirm('This will replace biometric attendance rows for employees with linked logs. Manual rows will be preserved. Continue?')) {
            $this->warn('Rebuild cancelled; no attendance records were changed.');
            return self::SUCCESS;
        }

        $employeePairs = [];
        foreach ($refreshDays as $day) {
            $employeePairs[$day['tenant_id'] . ':' . $day['employee_id']] = [
                'tenant_id' => $day['tenant_id'],
                'employee_id' => $day['employee_id'],
            ];
        }

        try {
            $deletedRows = 0;
            DB::transaction(function () use ($employeePairs, $refreshDays, $manualConflicts, $employees, $attendanceService, &$deletedRows): void {
                $deletedRows = $this->deleteBiometricRowsForEmployees(array_values($employeePairs));
                $progress = $this->output->createProgressBar(count($refreshDays) - count($manualConflicts));
                $progress->start();

                foreach ($refreshDays as $key => $day) {
                    if (isset($manualConflicts[$key])) {
                        continue;
                    }

                    $employee = $employees[$day['employee_id']] ?? null;
                    if ($employee) {
                        $attendanceService->refreshEmployeeDay($employee, $day['received_at']);
                    }
                    $progress->advance();
                }

                $progress->finish();
                $this->newLine(2);
            });
        } catch (Throwable $exception) {
            $this->error('Rebuild failed and the database transaction was rolled back: ' . $exception->getMessage());
            return self::FAILURE;
        }

        $this->info("Rebuild complete. Replaced {$deletedRows} biometric rows; preserved " . count($manualConflicts) . ' manual-conflict workdays.');
        return self::SUCCESS;
    }

    private function findManualConflicts(array $refreshDays): array
    {
        $conflicts = [];
        foreach (array_chunk(array_values($refreshDays), 250) as $days) {
            $tenantIds = array_values(array_unique(array_column($days, 'tenant_id')));
            $employeeIds = array_values(array_unique(array_column($days, 'employee_id')));
            $dates = array_values(array_unique(array_column($days, 'work_date')));

            $rows = Attendance::withoutGlobalScopes(['tenant', 'branch'])
                ->whereIn('tenant_id', $tenantIds)
                ->whereIn('employee_id', $employeeIds)
                ->whereIn('date', $dates)
                ->where(function ($query): void {
                    $query->whereNull('source')->orWhere('source', '!=', 'biometric');
                })
                ->get(['tenant_id', 'employee_id', 'date']);

            foreach ($rows as $row) {
                $date = $row->date?->toDateString() ?? (string) $row->getRawOriginal('date');
                $key = $this->attendanceKey((int) $row->tenant_id, (int) $row->employee_id, $date);
                if (isset($refreshDays[$key])) {
                    $conflicts[$key] = true;
                }
            }
        }

        return $conflicts;
    }

    private function deleteBiometricRowsForEmployees(array $employeePairs): int
    {
        $deleted = 0;
        foreach (array_chunk($employeePairs, 250) as $pairs) {
            $query = Attendance::withoutGlobalScopes(['tenant', 'branch'])
                ->where('source', 'biometric')
                ->where(function ($query) use ($pairs): void {
                    foreach ($pairs as $pair) {
                        $query->orWhere(function ($pairQuery) use ($pair): void {
                            $pairQuery->where('tenant_id', $pair['tenant_id'])
                                ->where('employee_id', $pair['employee_id']);
                        });
                    }
                });
            $deleted += $query->delete();
        }

        return $deleted;
    }

    private function attendanceKey(int $tenantId, int $employeeId, string $date): string
    {
        return $tenantId . ':' . $employeeId . ':' . $date;
    }
}
