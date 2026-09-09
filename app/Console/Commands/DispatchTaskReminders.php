<?php

namespace App\Console\Commands;

use App\Models\Reminder;
use App\Models\TaskNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class DispatchTaskReminders extends Command
{
    protected $signature = 'reminders:dispatch {--limit=100}';
    protected $description = 'Dispatch due task reminders and create in-app notifications';

    public function handle(): int
    {
        $count = 0;
        Reminder::query()->with('task.assignee')
            ->where('status', 'pending')
            ->where('remind_at', '<=', now())
            ->orderBy('remind_at')
            ->limit((int) $this->option('limit'))
            ->get()
            ->each(function (Reminder $reminder) use (&$count): void {
                $task = $reminder->task;
                $user = $task?->assignee;
                if (!$task || !$user) {
                    $reminder->update(['status' => 'failed', 'failure_reason' => 'Task assignee no longer exists.']);
                    return;
                }

                try {
                    DB::transaction(function () use ($reminder, $task, $user, &$count): void {
                    if ($reminder->channel === 'email') {
                        if (!$user->email) {
                            throw new \RuntimeException('Task assignee has no email address.');
                        }
                        Mail::raw("تذكير بالمهمة\n\n{$task->title}\n\n{$task->description}", function ($message) use ($user, $task): void {
                            $message->to($user->email, $user->name)->subject("تذكير بالمهمة: {$task->title}");
                        });
                    } elseif ($reminder->channel === 'in-app') {
                        TaskNotification::create([
                            'tenant_id' => $task->tenant_id,
                            'user_id' => $user->id,
                            'task_id' => $task->id,
                            'reminder_id' => $reminder->id,
                            'title' => 'تذكير بمهمة',
                            'message' => $task->title,
                        ]);
                    }
                    $reminder->update(['status' => 'sent', 'notified_at' => now()]);
                    $count++;
                    });
                } catch (\Throwable $exception) {
                    $reminder->update(['status' => 'failed', 'failure_reason' => $exception->getMessage()]);
                    $this->error("Reminder {$reminder->id} failed: {$exception->getMessage()}");
                }
            });

        $this->info("Dispatched {$count} reminder(s).");
        return self::SUCCESS;
    }
}
