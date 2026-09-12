<?php

namespace App\Http\Controllers;

use App\Services\AI\AIResponseService;
use App\Services\AI\DatabaseSchemaService;
use App\Services\AI\ReadOnlyQueryService;
use App\Services\AI\SQLGeneratorService;
use App\Services\AI\SQLValidatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class AIChatController extends Controller
{
    public function __construct(
        private readonly DatabaseSchemaService $schema,
        private readonly SQLGeneratorService $generator,
        private readonly SQLValidatorService $validator,
        private readonly ReadOnlyQueryService $query,
        private readonly AIResponseService $response,
    ) {}

    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:' . config('ai.max_message_chars', 4000)],
            'history' => ['sometimes', 'array', 'max:12'],
        ]);
        $question = trim($data['message']);
        $history = $data['history'] ?? [];
        $requestId = (string) Str::uuid();
        $phase = 'schema_introspection';
        $generatedSql = null;
        $queryParameters = [];
        $tenantId = $request->user()?->tenant_id;
        $audit = [
            'user_id' => $request->user()?->id,
            'tenant_id' => $tenantId,
            'question' => $question,
            'model' => config('ai.gemini_model'),
        ];

        try {
            $schema = $this->schema->getSchema();
            $phase = 'gemini_planning';
            $plan = $this->generator->generate($question, $history, $schema);
            $maxRepairs = max(0, min((int) config('ai.max_repair_attempts', 2), 3));
            $lastError = null;

            // Attempt zero is the original plan; every repair is independently validated and read-only executed.
            for ($attempt = 0; $attempt <= $maxRepairs; $attempt++) {
                if (!empty($plan['clarification_needed']) && empty($plan['sql'])) {
                    $this->audit($audit + ['validation_passed' => true, 'row_count' => 0]);
                    return response()->json(['success' => true, 'answer' => $plan['clarification_needed'], 'data' => [], 'meta' => ['rows' => 0, 'attempts' => $attempt + 1]]);
                }

                try {
                    $parameters = (array) ($plan['parameters'] ?? []);
                    $queryParameters = $parameters;
                    if ($tenantId !== null) $parameters['tenant_id'] = $tenantId;
                    $generatedSql = is_string($plan['sql'] ?? null) ? $plan['sql'] : null;
                    $phase = 'schema_and_sql_validation';
                    $sql = $this->validator->validate($plan['sql'] ?? null, $parameters, $schema, $tenantId);
                    $generatedSql = $sql;
                    $phase = 'readonly_database_query';
                    $result = $this->query->run($sql, $parameters);
                    $phase = 'gemini_response';
                    $answer = $this->response->answer($question, $result['rows'], $plan);
                    $this->audit($audit + ['sql' => $sql, 'parameters' => $parameters, 'validation_passed' => true, 'duration_ms' => $result['duration_ms'], 'row_count' => count($result['rows'])]);
                    return response()->json(['success' => true, 'answer' => $answer, 'data' => $result['rows'], 'meta' => ['rows' => count($result['rows']), 'duration_ms' => $result['duration_ms'], 'intent' => $plan['intent'] ?? null, 'attempts' => $attempt + 1]]);
                } catch (Throwable $e) {
                    $lastError = $e;
                    if ($attempt >= $maxRepairs) throw $e;
                    $phase = 'gemini_repair';
                    Log::warning('AI query attempt failed; requesting a bounded repair', [
                        'request_id' => $requestId,
                        'attempt' => $attempt + 1,
                        'error' => $e->getMessage(),
                    ]);
                    $plan = $this->generator->repair($question, $history, $plan, $generatedSql, $e->getMessage(), $schema);
                }
            }

            throw $lastError ?? new \RuntimeException('No safe query attempt was produced.');
        } catch (Throwable $e) {
            Log::error('AI database query failed', [
                'request_id' => $requestId,
                'user_id' => $request->user()?->id,
                'phase' => $phase,
                'sql' => $generatedSql,
                'parameters' => $queryParameters,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->audit($audit + ['sql' => $generatedSql, 'parameters' => $queryParameters, 'validation_passed' => false, 'error' => $e->getMessage()]);
            $safePhase = match (true) {
                $phase === 'schema_introspection' => 'schema',
                str_starts_with($phase, 'gemini') => 'gemini',
                $phase === 'readonly_database_query' => 'database',
                default => 'sql_validation',
            };
            $safeError = preg_replace('/(x-goog-api-key|Authorization|GEMINI_API_KEY|password|secret)\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $e->getMessage());
            $message = config('ai.expose_errors', true)
                ? 'فشل الطلب في مرحلة ' . $safePhase . ': ' . mb_substr((string) $safeError, 0, 1200)
                : 'تعذر تنفيذ طلب القراءة بأمان. حاول إعادة صياغة السؤال.';
            return response()->json(['success' => false, 'message' => $message, 'request_id' => $requestId, 'failed_at' => $safePhase], 422);
        }
    }

    public function schema(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->schema->getSchema()]);
    }

    private function audit(array $data): void
    {
        if (Schema::hasTable('ai_query_audits')) \App\Models\AIQueryAudit::create($data);
    }
}
