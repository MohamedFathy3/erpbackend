<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AuditTrailMiddleware
{
    private const SECRET_KEY = '/password|token|secret|authorization|cookie|credit.?card|card.?number|cvv|cvc|pin|api.?key|private.?key|refresh.?token/i';

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
        $response = null;
        $thrown = null;

        try {
            $response = $next($request);
            return $response;
        } catch (Throwable $exception) {
            $thrown = $exception;
            throw $exception;
        } finally {
            try {
                $status = $response?->getStatusCode() ?? $this->exceptionStatus($thrown);
                $responseData = $this->responseData($response);
                $applicationFailure = $status >= 400 || $this->responseIndicatesFailure($responseData);
                if ($this->shouldRecord($request, $status, $applicationFailure)) {
                    $this->record($request, $response, $thrown, $status, (int) round((hrtime(true) - $startedAt) / 1_000_000));
                }
            } catch (Throwable $loggingFailure) {
                // Auditing must never turn a successful business request into a failure.
                report($loggingFailure);
            }
        }
    }

    private function shouldRecord(Request $request, int $status, bool $applicationFailure = false): bool
    {
        $path = strtolower(trim($request->path(), '/'));
        if (in_array($request->method(), ['OPTIONS'], true)) {
            return false;
        }
        // Avoid recursive noise from loading this very page. Retry POSTs remain audited.
        if ($request->isMethod('GET') && preg_match('#(?:^|/)super-admin/audit-trail/?$#', $path)) {
            return false;
        }

        $actor = $request->user() ?: auth('sanctum')->user();
        return $actor !== null
            || $this->isLoginAttempt($request)
            || in_array($status, [401, 403], true)
            || $applicationFailure;
    }

    private function record(Request $request, ?Response $response, ?Throwable $exception, int $status, int $durationMs): void
    {
        $actor = $request->user() ?: auth('sanctum')->user();
        $loginAttempt = $this->isLoginAttempt($request);
        $responseData = $this->responseData($response);

        // Token-login endpoints return the authenticated identity in their response,
        // because no authenticated principal exists on the request yet.
        if (!$actor && $loginAttempt && $status < 400 && is_array($responseData)) {
            $actor = $this->loginActor($responseData);
        }

        $actorType = $actor ? get_class($actor) : null;
        $actorId = $actor?->getAuthIdentifier();
        $actorName = $actor?->name ?? $actor?->full_name ?? null;
        $actorEmail = $actor?->email;

        if (!$actor && $loginAttempt) {
            $attempted = $request->input('email', $request->input('identifier'));
            $actorName = 'محاولة تسجيل دخول';
            $actorEmail = is_string($attempted) ? $this->maskIdentifier($attempted) : null;
        }

        $tenantId = $this->tenantId($request, $actor);
        $tenant = $tenantId ? DB::table('tenants')->where('id', $tenantId)->first(['name', 'slug']) : null;
        $tenantName = $tenant?->name;
        $tenantSlug = $tenant?->slug;
        $retryHeader = $request->header('X-Audit-Retry-Event');
        $retryParentId = ($actor->super_admin ?? false)
            && is_string($retryHeader)
            && ctype_digit($retryHeader)
            && DB::table('audit_events')->where('id', (int) $retryHeader)->exists()
                ? (int) $retryHeader
                : null;
        $eventType = $this->eventType($request, $loginAttempt);
        $outcome = ($status >= 400 || $this->responseIndicatesFailure($responseData)) ? 'failed' : 'success';
        $error = $exception instanceof ValidationException
            ? json_encode(['message' => $exception->getMessage(), 'errors' => $exception->errors()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : ($exception ? $exception->getMessage() : $this->responseError($responseData, $status));
        $requestPath = $request->path();
        if (str_contains(strtolower($requestPath), '/audit/page-view')) {
            $requestPath = 'page-view: ' . (string) $request->input('page', '');
        } elseif (str_contains(strtolower($requestPath), '/audit/print')) {
            $requestPath = 'print: ' . (string) $request->input('document_title', '');
        }
        $requestData = $loginAttempt
            ? ['identifier' => $this->maskIdentifier((string) $request->input('email', $request->input('identifier', ''))), 'login_attempt' => true]
            : $request->except(['_token']);

        $payload = [
            'retry_of_event_id' => $retryParentId,
            'tenant_id' => $tenantId,
            'tenant_name' => $tenantName,
            'tenant_slug' => $tenantSlug,
            'actor_id' => $actorId ? (int) $actorId : null,
            'actor_type' => $actorType ? class_basename($actorType) : null,
            'actor_name' => $actorName ? mb_substr((string) $actorName, 0, 255) : null,
            'actor_email' => $actorEmail ? mb_substr((string) $actorEmail, 0, 255) : null,
            'branch_id' => $actor?->branch_id ? (int) $actor->branch_id : null,
            'event_type' => $eventType,
            'outcome' => $outcome,
            'http_method' => strtoupper($request->method()),
            'request_path' => mb_substr($requestPath, 0, 512),
            'status_code' => $status > 0 && $status <= 599 ? $status : null,
            'duration_ms' => max(0, $durationMs),
            'query_data' => $this->jsonValue($this->sanitize($request->query())),
            'request_data' => $this->jsonValue($this->sanitize($requestData)),
            'error_summary' => $error ? mb_substr((string) $this->sanitize($error), 0, 4000) : null,
            'client_ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            'created_at' => now(),
        ];

        DB::table('audit_events')->insert($payload);
    }

    private function tenantId(Request $request, mixed $actor): ?int
    {
        $tenantId = $actor?->tenant_id ?: (app()->bound('currentTenantId') ? app('currentTenantId') : null);
        if (!$tenantId && ($actor->super_admin ?? false)) {
            $tenantId = $request->input('tenant_id', $request->query('tenant_id'));
            if (!$tenantId) {
                $slug = trim((string) $request->header('X-Tenant-Slug', ''));
                if ($slug !== '') {
                    $tenantId = Tenant::withoutGlobalScopes()->where('slug', $slug)->value('id');
                }
            }
        }

        if (!$tenantId || !is_numeric($tenantId)) {
            return null;
        }

        return DB::table('tenants')->where('id', (int) $tenantId)->exists() ? (int) $tenantId : null;
    }

    private function loginActor(array $responseData): mixed
    {
        $type = strtolower((string) ($responseData['type'] ?? ''));
        $identity = $responseData['data'] ?? [];
        if (is_object($identity)) {
            $identity = (array) $identity;
        }
        $id = is_array($identity) ? ($identity['id'] ?? null) : null;
        if (!$id || !is_numeric($id)) {
            return null;
        }

        $model = match ($type) {
            'admin' => Admin::class,
            'employee' => Employee::class,
            default => User::class,
        };

        try {
            return $model::withoutGlobalScopes()->find((int) $id);
        } catch (Throwable) {
            return null;
        }
    }

    private function isLoginAttempt(Request $request): bool
    {
        $path = strtolower(trim($request->path(), '/'));
        return $request->isMethod('POST') && (bool) preg_match('#(?:^|/)(?:login|admin/login|sales-representative/login)$#', $path);
    }

    private function eventType(Request $request, bool $loginAttempt): string
    {
        if ($loginAttempt) {
            return 'login';
        }
        if (str_contains(strtolower($request->path()), '/audit/print')) {
            return 'print';
        }
        if (str_contains(strtolower($request->path()), '/audit/page-view')) {
            return 'read';
        }

        return match (strtoupper($request->method())) {
            'GET', 'HEAD' => 'read',
            'POST' => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => 'action',
        };
    }

    private function exceptionStatus(?Throwable $exception): int
    {
        if (!$exception) {
            return 500;
        }
        if ($exception instanceof AuthenticationException) {
            return 401;
        }
        if ($exception instanceof AuthorizationException) {
            return 403;
        }
        if ($exception instanceof ValidationException) {
            return 422;
        }
        if ($exception instanceof ModelNotFoundException) {
            return 404;
        }
        if ($exception instanceof HttpExceptionInterface) {
            return $exception->getStatusCode();
        }
        return 500;
    }

    private function responseData(?Response $response): ?array
    {
        if (!$response || !method_exists($response, 'getContent')) {
            return null;
        }
        $content = $response->getContent();
        if (!is_string($content) || $content === '') {
            return null;
        }
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function responseError(?array $data, int $status): ?string
    {
        if ($status < 400 && !$this->responseIndicatesFailure($data)) {
            return null;
        }
        if (!$data) {
            return 'HTTP ' . $status;
        }
        $nested = is_array($data['data'] ?? null) ? $data['data'] : [];
        $message = $data['message'] ?? $data['error'] ?? $nested['message'] ?? $nested['error'] ?? $data['code'] ?? null;
        if (isset($data['errors']) && is_array($data['errors'])) {
            return (string) json_encode([
                'message' => $message,
                'errors' => $this->sanitize($data['errors']),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (is_array($message)) {
            $message = json_encode($this->sanitize($message), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return is_scalar($message) ? (string) $message : 'HTTP ' . $status;
    }

    private function responseIndicatesFailure(?array $data): bool
    {
        if (!$data) {
            return false;
        }
        $success = $data['success'] ?? null;
        $status = $data['status'] ?? null;
        if ($success === false || (is_string($success) && strtolower($success) === 'false')) {
            return true;
        }
        if ($status === false || (is_string($status) && in_array(strtolower($status), ['error', 'failed', 'failure'], true))) {
            return true;
        }
        if (isset($data['errors']) && is_array($data['errors']) && $data['errors'] !== []) {
            return true;
        }
        return is_array($data['data'] ?? null) && $this->responseIndicatesFailure($data['data']);
    }

    private function sanitize(mixed $value, int $depth = 0, ?string $key = null): mixed
    {
        if ($key !== null && preg_match(self::SECRET_KEY, $key)) {
            return '[REDACTED]';
        }
        if ($depth > 6) {
            return '[TRUNCATED]';
        }
        if (is_array($value)) {
            $clean = [];
            $count = 0;
            foreach ($value as $childKey => $childValue) {
                if ($count++ >= 120) {
                    $clean['_truncated'] = true;
                    break;
                }
                $clean[$childKey] = $this->sanitize($childValue, $depth + 1, (string) $childKey);
            }
            return $clean;
        }
        if (is_object($value)) {
            return '[OMITTED]';
        }
        if (is_string($value)) {
            return mb_substr($value, 0, 2000);
        }
        return $value;
    }

    private function jsonValue(mixed $value): ?string
    {
        if ($value === [] || $value === null) {
            return null;
        }
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (!is_string($encoded)) {
            return null;
        }
        if (strlen($encoded) > 16000) {
            $encoded = json_encode(['_truncated' => true, 'preview' => mb_substr($encoded, 0, 15500)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $encoded;
    }

    private function maskIdentifier(string $identifier): string
    {
        if (str_contains($identifier, '@')) {
            [$local, $domain] = explode('@', $identifier, 2);
            return (mb_substr($local, 0, 1) ?: '*') . '***@' . $domain;
        }
        return mb_substr($identifier, 0, 2) . '***';
    }
}
