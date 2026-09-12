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
        $question = trim($data['message']);
        $requestId = (string) Str::uuid();
        $audit = ['user_id' => $request->user()?->id, 'question' => $question, 'model' => config('ai.gemini_model')];
        try {
            $plan = $this->generator->generate($question, $data['history'] ?? []);
            if (!empty($plan['clarification_needed']) && empty($plan['sql'])) {
                $this->audit($audit + ['validation_passed' => true, 'row_count' => 0]);
                return response()->json(['success' => true, 'answer' => $plan['clarification_needed'], 'data' => [], 'meta' => ['rows' => 0]]);
            }
            $parameters = (array) ($plan['parameters'] ?? []);
            $tenantId = $request->user()?->tenant_id;
            if ($tenantId !== null) $parameters['tenant_id'] = $tenantId;
            $sql = $this->validator->validate($plan['sql'] ?? null, $parameters, $this->schema->getSchema(), $tenantId);
            $result = $this->query->run($sql, $parameters);
            $answer = $this->response->answer($question, $result['rows'], $plan);
            $this->audit($audit + ['sql' => $sql, 'parameters' => $parameters, 'validation_passed' => true, 'duration_ms' => $result['duration_ms'], 'row_count' => count($result['rows'])]);
            return response()->json(['success' => true, 'answer' => $answer, 'data' => $result['rows'], 'meta' => ['rows' => count($result['rows']), 'duration_ms' => $result['duration_ms'], 'intent' => $plan['intent'] ?? null]]);
        } catch (Throwable $e) {
            Log::error('AI database query failed', ['request_id' => $requestId, 'user_id' => $request->user()?->id, 'exception' => get_class($e), 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->audit($audit + ['validation_passed' => false, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'تعذر تنفيذ طلب القراءة بأمان. حاول إعادة صياغة السؤال.', 'request_id' => $requestId], 422);
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
