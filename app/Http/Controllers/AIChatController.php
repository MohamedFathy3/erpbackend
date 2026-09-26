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
        $data = $request->validate(['message' => ['required', 'string', 'max:' . config('ai.max_message_chars', 4000)], 'history' => ['sometimes', 'array', 'max:12']]);
        $question = trim($data['message']); $history = $data['history'] ?? []; $requestId = (string) Str::uuid(); $phase = 'schema_introspection';
        $tenantId = $request->user()?->tenant_id ?: (app()->bound('currentTenantId') ? app('currentTenantId') : null);
        $audit = ['user_id' => $request->user()?->id, 'tenant_id' => $tenantId, 'question' => $question, 'model' => config('ai.gemini_model')];
        try {
            $schema = $this->schema->getSchema(); $phase = 'gemini_planning';
            $generated = $this->generator->generate($question, $history, $schema);
            $plans = $this->normalizePlans($generated);
            if (!$plans) throw new \RuntimeException('لم يتم إنشاء أي خطة قراءة آمنة لهذا الطلب.');
            $maxRepairs = max(0, min((int) config('ai.max_repair_attempts', 2), 3)); $sections = [];
            foreach ($plans as $planIndex => $originalPlan) {
                $plan = $originalPlan; $section = ['intent' => $plan['intent'] ?? 'report', 'rows' => [], 'clarification_needed' => null, 'attempts' => 0]; $generatedSql = null;
                for ($attempt = 0; $attempt <= $maxRepairs; $attempt++) {
                    $section['attempts'] = $attempt + 1;
                    if (!empty($plan['clarification_needed']) && empty($plan['sql'])) { $section['clarification_needed'] = $plan['clarification_needed']; break; }
                    try {
                        $parameters = (array) ($plan['parameters'] ?? []); if ($tenantId !== null) $parameters['tenant_id'] = $tenantId;
                        $generatedSql = is_string($plan['sql'] ?? null) ? $plan['sql'] : null; $phase = 'schema_and_sql_validation';
                        $sql = $this->validator->validate($plan['sql'] ?? null, $parameters, $schema, $tenantId); $phase = 'readonly_database_query';
                        $result = $this->query->run($sql, $parameters); $section['rows'] = $result['rows']; $section['duration_ms'] = $result['duration_ms']; $section['sql'] = $sql;
                        $this->audit($audit + ['sql' => $sql, 'parameters' => $parameters, 'validation_passed' => true, 'duration_ms' => $result['duration_ms'], 'row_count' => count($result['rows']), 'intent' => $section['intent']]); break;
                    } catch (Throwable $e) {
                        if ($attempt >= $maxRepairs) { $section['clarification_needed'] = 'تعذر تنفيذ هذا التقرير بأمان: ' . mb_substr($e->getMessage(), 0, 300); break; }
                        $phase = 'gemini_repair'; Log::warning('AI multi-report query attempt failed; requesting bounded repair', ['request_id' => $requestId, 'plan' => $planIndex, 'attempt' => $attempt + 1, 'error' => $e->getMessage()]);
                        $plan = $this->generator->repair($question, $history, $plan, $generatedSql, $e->getMessage(), $schema);
                    }
                }
                $sections[] = $section;
            }
            $phase = 'gemini_response'; $answer = $this->response->answerSections($question, $sections); $rowCount = array_sum(array_map(fn ($section) => count($section['rows']), $sections));
            $this->audit($audit + ['validation_passed' => true, 'row_count' => $rowCount, 'query_count' => count($sections)]);
            return response()->json(['success' => true, 'answer' => $answer, 'data' => count($sections) === 1 ? $sections[0]['rows'] : [], 'sections' => $sections, 'meta' => ['rows' => $rowCount, 'query_count' => count($sections), 'attempts' => array_sum(array_map(fn ($section) => $section['attempts'], $sections))]]);
        } catch (Throwable $e) {
            Log::error('AI database query failed', ['request_id' => $requestId, 'user_id' => $request->user()?->id, 'phase' => $phase, 'exception' => get_class($e), 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]); $this->audit($audit + ['validation_passed' => false, 'error' => $e->getMessage()]);
            $safePhase = match (true) { $phase === 'schema_introspection' => 'schema', str_starts_with($phase, 'gemini') => 'gemini', $phase === 'readonly_database_query' => 'database', default => 'sql_validation' };
            $safeError = preg_replace('/(x-goog-api-key|Authorization|GEMINI_API_KEY|password|secret)\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $e->getMessage()); $message = config('ai.expose_errors', true) ? 'فشل الطلب في مرحلة ' . $safePhase . ': ' . mb_substr((string) $safeError, 0, 1200) : 'تعذر تنفيذ طلب القراءة بأمان. حاول إعادة صياغة السؤال.';
            return response()->json(['success' => false, 'message' => $message, 'request_id' => $requestId, 'failed_at' => $safePhase], 422);
        }
    }

    private function normalizePlans(array $generated): array
    {
        $plans = $generated['plans'] ?? null;
        if (is_array($plans) && array_is_list($plans)) return array_values(array_filter($plans, 'is_array'));
        return isset($generated['sql']) || isset($generated['clarification_needed']) ? [$generated] : [];
    }

    public function schema(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->schema->getSchema()]);
    }

    private function audit(array $data): void
    {
        if (!Schema::hasTable('ai_query_audits')) return;
        $allowed = ['user_id', 'tenant_id', 'question', 'sql', 'parameters', 'validation_passed', 'duration_ms', 'row_count', 'model', 'error'];
        \App\Models\AIQueryAudit::create(array_intersect_key($data, array_flip($allowed)));
    }
}
