<?php
namespace App\Console\Commands;

use App\Models\BiometricDevice;
use App\Services\BiometricAttendanceService;
use Illuminate\Console\Command;

class SyncBiometricAttendance extends Command
{
    protected $signature = 'biometric:sync {--device= : مزامنة جهاز محدد}';
    protected $description = 'Pull attendance logs from active ZKTeco devices for every tenant';

    public function handle(BiometricAttendanceService $service): int
    {
        $query = BiometricDevice::withoutGlobalScopes()->where('is_active', true);
        if ($this->option('device')) $query->whereKey($this->option('device'));
        foreach ($query->get() as $device) {
            try {
                app()->instance('currentTenantId', $device->tenant_id);
                $result = $service->sync($device);
                $this->info("{$device->name}: {$result['imported']} new logs");
            } catch (\Throwable $e) {
                $this->error("{$device->name}: {$e->getMessage()}");
            }
        }
        return self::SUCCESS;
    }
}
