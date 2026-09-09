<?php
namespace App\Console\Commands;

use App\Jobs\SendTrialEmail;
use App\Models\Tenant;
use Illuminate\Console\Command;

class ProcessTrials extends Command
{
    protected $signature = 'trials:process';
    protected $description = 'Expire trials and send trial-ending reminders';
    public function handle(): int
    {
        Tenant::withoutGlobalScopes()->where('subscription_status','trial')->chunkById(100, function ($tenants) {
            foreach ($tenants as $tenant) {
                if (!$tenant->trial_ends_at) continue;
                $days=(int) now()->startOfDay()->diffInDays($tenant->trial_ends_at->startOfDay(), false);
                if ($days < 0) { $tenant->update(['subscription_status'=>'expired','status'=>'suspended']); continue; }
                if (in_array($days,[3,1], true) && (!$tenant->last_trial_reminder_at || !$tenant->last_trial_reminder_at->isToday())) {
                    $admin=$tenant->admins()->whereNotNull('email')->first();
                    if ($admin) { SendTrialEmail::dispatch($admin->email, "Your ERP trial ends in {$days} day(s)", "<p>Hello {$admin->name},</p><p>Your free trial for <strong>{$tenant->name}</strong> ends in {$days} day(s). Contact us to continue.</p>"); $tenant->update(['last_trial_reminder_at'=>now()]); }
                }
            }
        });
        $this->info('Trials processed.'); return self::SUCCESS;
    }
}
