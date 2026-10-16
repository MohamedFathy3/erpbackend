<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditTrailController extends Controller
{
    public function index(Request $request)
    {
        $this->guard($request);
        $filters = $request->validate([
            'tenant_id' => ['nullable', 'integer', 'min:1'],
            'actor_id' => ['nullable', 'integer', 'min:1'],
            'outcome' => ['nullable', 'in:success,failed'],
            'event_type' => ['nullable', 'in:read,create,update,delete,login,print,action'],
            'method' => ['nullable', 'in:GET,HEAD,POST,PUT,PATCH,DELETE'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:160'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = DB::table('audit_events');
        $this->applyFilters($query, $filters);
        $base = clone $query;
        $summary = [
            'total' => (clone $base)->count(),
            'failed' => (clone $base)->where('outcome', 'failed')->count(),
            'successful' => (clone $base)->where('outcome', 'success')->count(),
            'prints' => (clone $base)->where('event_type', 'print')->count(),
            'denied' => (clone $base)->whereIn('status_code', [401, 403])->count(),
        ];

        $events = $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 50));
        $events->getCollection()->transform(function (object $event): object {
            $event->query_data = $this->decodeJsonData($event->query_data);
            $event->request_data = $this->decodeJsonData($event->request_data);
            $event->retryable = $this->isRetryable($event);
            return $event;
        });

        return response()->json(['data' => $events, 'summary' => $summary]);
    }

    private function applyFilters($query, array $filters): void
    {
        if (!empty($filters['tenant_id'])) {
            $query->where('tenant_id', (int) $filters['tenant_id']);
        }
        if (!empty($filters['actor_id'])) {
            $query->where('actor_id', (int) $filters['actor_id']);
        }
        if (!empty($filters['outcome'])) {
            $query->where('outcome', $filters['outcome']);
        }
        if (!empty($filters['event_type'])) {
            $query->where('event_type', $filters['event_type']);
        }
        if (!empty($filters['method'])) {
            $query->where('http_method', strtoupper($filters['method']));
        }
        if (!empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from'] . ' 00:00:00');
        }
        if (!empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to'] . ' 23:59:59');
        }
        if (!empty($filters['q'])) {
            $term = '%' . addcslashes($filters['q'], '%_\\') . '%';
            $query->where(function ($nested) use ($term): void {
                $nested->where('actor_name', 'like', $term)
                    ->orWhere('actor_email', 'like', $term)
                    ->orWhere('tenant_name', 'like', $term)
                    ->orWhere('request_path', 'like', $term)
                    ->orWhere('error_summary', 'like', $term);
            });
        }
    }

    private function guard(Request $request): void
    {
        abort_unless($request->user() && (bool) ($request->user()->super_admin ?? false), 403);
    }

    private function isRetryable(object $event): bool
    {
        if ($event->outcome !== 'failed' || in_array($event->event_type, ['login', 'print'], true)) {
            return false;
        }

        $method = strtoupper((string) $event->http_method);
        if (in_array($method, ['GET', 'HEAD'], true)) {
            return true;
        }
        if ($event->event_type === 'read') {
            return false;
        }
        if (!in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            return false;
        }

        $path = strtolower((string) $event->request_path);
        if (str_contains($path, '/audit/') || preg_match('#(?:^|/)(?:login|logout|admin-logout)(?:/|$)#', $path)) {
            return false;
        }

        $requestData = $this->decodeJsonData($event->request_data);
        return is_array($requestData) && !$this->containsRedactedValue($requestData);
    }

    private function decodeJsonData(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function containsRedactedValue(mixed $value): bool
    {
        if (is_string($value)) {
            return in_array($value, ['[REDACTED]', '[OMITTED]'], true);
        }
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $child) {
            if ($this->containsRedactedValue($child)) {
                return true;
            }
        }
        return false;
    }
}
