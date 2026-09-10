<?php

namespace App\Http\Controllers;

use App\Models\GoogleConnection;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\TaskNotification;
use App\Services\GoogleCalendarService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function __construct(private readonly GoogleCalendarService $google) {}

    public function index(Request $request)
    {
        $query = Task::query()->with(['assignee:id,name,email', 'reminders'])->latest('due_date');
        if ($request->filled('status')) $query->where('status', $request->string('status'));
        if ($request->filled('assigned_to')) $query->where('assigned_to', $request->integer('assigned_to'));
        return response()->json(['data' => $query->paginate($request->integer('per_page', 25))]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $task = Task::create($data);
        $this->syncCalendar($request, $task);
        return response()->json(['data' => $task->fresh(['assignee', 'reminders', 'calendarEvent'])], 201);
    }

    public function show(Task $task)
    {
        return response()->json(['data' => $task->load(['assignee', 'reminders', 'calendarEvent'])]);
    }

    public function update(Request $request, Task $task)
    {
        $task->update($this->validated($request, true));
        return response()->json(['data' => $task->fresh(['assignee', 'reminders', 'calendarEvent'])]);
    }

    public function destroy(Task $task)
    {
        $task->delete();
        return response()->json(['message' => 'Task deleted.']);
    }

    public function addReminder(Request $request, Task $task)
    {
        $data = $request->validate([
            'remind_at' => 'required|date',
            'channel' => ['required', Rule::in(['in-app', 'email', 'whatsapp'])],
        ]);
        $reminder = $task->reminders()->create([...$data, 'tenant_id' => $request->user()->tenant_id]);
        return response()->json(['data' => $reminder], 201);
    }

    public function notifications(Request $request)
    {
        $query = TaskNotification::query()->where('user_id', $request->user()->id)->latest();
        if ($request->boolean('unread')) $query->where('is_read', false);
        return response()->json(['data' => $query->paginate($request->integer('per_page', 25))]);
    }

    public function markNotificationRead(Request $request, TaskNotification $notification)
    {
        abort_unless($notification->user_id === $request->user()->id, 404);
        $notification->update(['is_read' => true, 'read_at' => now()]);
        return response()->json(['data' => $notification]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'title' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => 'nullable|string|max:5000',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'related_to_type' => 'nullable|string|max:255',
            'related_to_id' => 'nullable|integer',
            'due_date' => 'nullable|date',
            'status' => ['nullable', Rule::in(['open', 'in_progress', 'completed', 'cancelled'])],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
        ]);
    }

    private function syncCalendar(Request $request, Task $task): void
    {
        if (!$task->due_date) return;
        $connection = GoogleConnection::query()->where('user_id', $request->user()->id)->where('status', 'connected')->first();
        if (!$connection) return;
        try {
            $created = $this->google->createEvent($connection, [
                'summary' => $task->title,
                'description' => $task->description,
                'starts_at' => $task->due_date->toDateTimeString(),
                'ends_at' => $task->due_date->copy()->addHour()->toDateTimeString(),
            ]);
            $task->update(['calendar_event_id' => \App\Models\CalendarEvent::create([
                'user_id' => $request->user()->id,
                'tenant_id' => $request->user()->tenant_id,
                'google_connection_id' => $connection->id,
                'google_event_id' => $created['id'],
                'summary' => $task->title,
                'description' => $task->description,
                'starts_at' => $task->due_date,
                'ends_at' => $task->due_date->copy()->addHour(),
                'google_payload' => $created['payload'],
            ])->id]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
