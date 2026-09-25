<?php
namespace App\Http\Controllers;

use App\Models\AttendanceRule;
use App\Models\Attendance;
use App\Models\BiometricDevice;
use App\Models\Employee;
use App\Services\BiometricAttendanceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BiometricAttendanceController extends Controller
{
    public function devices() { return response()->json(['status' => true, 'data' => BiometricDevice::with('branch')->latest()->get()]); }

    public function storeDevice(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'ip_address' => 'required|ip', 'port' => 'nullable|integer|min:1|max:65535', 'protocol' => 'nullable|in:tcp,udp', 'device_password' => 'nullable|integer|min:0', 'branch_id' => 'nullable|exists:branches,id', 'is_active' => 'boolean']);
        return response()->json(['status' => true, 'data' => BiometricDevice::create($data)], 201);
    }

    public function updateDevice(Request $request, BiometricDevice $device)
    {
        $data = $request->validate(['name' => 'sometimes|string|max:120', 'ip_address' => 'sometimes|ip', 'port' => 'sometimes|integer|min:1|max:65535', 'protocol' => 'sometimes|in:tcp,udp', 'device_password' => 'sometimes|integer|min:0', 'branch_id' => 'nullable|exists:branches,id', 'is_active' => 'boolean']);
        $device->update($data);
        return response()->json(['status' => true, 'data' => $device->fresh('branch')]);
    }

    public function testDevice(BiometricDevice $device, BiometricAttendanceService $service) { return response()->json(['status' => true, 'data' => $service->testConnection($device)]); }
    public function syncDevice(BiometricDevice $device, BiometricAttendanceService $service) { return response()->json(['status' => true, 'data' => $service->sync($device)]); }
    public function deviceUsers(BiometricDevice $device, BiometricAttendanceService $service) { return response()->json(['status' => true, 'data' => $service->deviceUsers($device)]); }

    public function storeDeviceUser(Request $request, BiometricDevice $device, BiometricAttendanceService $service)
    {
        $data = $request->validate([
            'uid' => 'required|integer|min:1|max:65535', 'user_id' => 'required|string|max:32',
            'name' => 'required|string|max:100', 'password' => 'nullable|string|max:32',
            'role' => 'nullable|integer|min:0|max:14', 'card_no' => 'nullable|integer|min:0',
            'employee_id' => 'nullable|exists:employees,id',
        ]);
        return response()->json(['status' => true, 'data' => $service->createDeviceUser($device, $data)], 201);
    }

    public function mappings()
    {
        return response()->json(['status' => true, 'data' => Employee::query()->select(['id', 'employee_code', 'name', 'name_ar', 'biometric_user_id'])->with('branch')->orderBy('name')->get()]);
    }

    public function updateMapping(Request $request, Employee $employee)
    {
        $data = $request->validate(['biometric_user_id' => 'nullable|string|max:32']);
        $employee->update($data);
        return response()->json(['status' => true, 'data' => $employee->only(['id', 'employee_code', 'name', 'biometric_user_id'])]);
    }

    public function rules() { return response()->json(['status' => true, 'data' => AttendanceRule::latest()->get()]); }

    public function storeRule(Request $request)
    {
        $data = $this->validatedRule($request);
        return response()->json(['status' => true, 'data' => AttendanceRule::create($data)], 201);
    }

    public function updateRule(Request $request, AttendanceRule $rule)
    {
        $rule->update($this->validatedRule($request, true));
        return response()->json(['status' => true, 'data' => $rule->fresh()]);
    }

    public function payrollPreview(Request $request, Employee $employee, BiometricAttendanceService $service)
    {
        $data = $request->validate(['period_start' => 'required|date', 'period_end' => 'required|date|after_or_equal:period_start', 'rule_id' => 'nullable|exists:attendance_rules,id']);
        return response()->json(['status' => true, 'data' => $service->previewPayroll($employee, Carbon::parse($data['period_start']), Carbon::parse($data['period_end']), isset($data['rule_id']) ? AttendanceRule::find($data['rule_id']) : null)]);
    }

    public function report(Request $request, BiometricAttendanceService $service)
    {
        $data = $request->validate([
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
        ]);
        $from = Carbon::parse($data['period_start']);
        $to = Carbon::parse($data['period_end']);
        $rule = AttendanceRule::where('is_active', true)->latest('id')->first();
        $employees = Employee::query()->where('is_active', true)->orderBy('name')->get();
        $attendance = Attendance::with('employee')->whereBetween('date', [$from->toDateString(), $to->toDateString()])->orderBy('date')->get();
        $summary = $employees->map(fn (Employee $employee) => $service->previewPayroll($employee, $from, $to, $rule));

        return response()->json(['status' => true, 'data' => [
            'period_start' => $from->toDateString(), 'period_end' => $to->toDateString(), 'rule' => $rule,
            'summary' => $summary, 'attendance' => $attendance->map(fn (Attendance $row) => [
                'employee_id' => $row->employee_id, 'employee_name' => $row->employee?->name,
                'employee_code' => $row->employee?->employee_code, 'date' => $row->date?->format('Y-m-d'),
                'check_in' => $row->check_in?->format('H:i'), 'check_out' => $row->check_out?->format('H:i'),
                'status' => $row->status, 'source' => $row->source, 'late_minutes' => (int) ($row->late_minutes ?? 0),
                'worked_minutes' => (int) ($row->worked_minutes ?? 0),
            ])->values(),
        ]]);
    }

    private function validatedRule(Request $request, bool $sometimes = false): array
    {
        $prefix = $sometimes ? 'sometimes|' : '';
        return $request->validate([
            'name' => $prefix . 'required|string|max:120', 'work_start' => $prefix . 'required|date_format:H:i', 'work_end' => $prefix . 'required|date_format:H:i',
            'grace_minutes' => $prefix . 'integer|min:0|max:1440', 'late_deduction_type' => $prefix . 'in:none,fixed,hourly,percent', 'late_deduction_value' => $prefix . 'numeric|min:0',
            'absence_deduction_type' => $prefix . 'in:none,fixed,daily,percent', 'absence_deduction_value' => $prefix . 'numeric|min:0', 'deduct_early_leave' => 'boolean', 'minimum_worked_minutes' => 'integer|min:0', 'is_active' => 'boolean',
        ]);
    }
}
