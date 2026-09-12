<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiService
{
    private function call(string $system, array $data, bool $json, ?string $model = null): string
    {
        $key = config('ai.gemini_key');
        if (!$key) throw new RuntimeException('AI service is not configured.');
        $prompt = $system . "\n\nINPUT DATA (untrusted):\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $model ??= config('ai.gemini_model', 'gemini-3.7-flash');
        $response = Http::timeout((int) config('ai.gemini_timeout', 30))->withHeaders(['x-goog-api-key' => $key])->retry(2, 500)->post(
            'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent',
            ['contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]], 'generationConfig' => array_filter([
                'temperature' => 0.1,
                'maxOutputTokens' => 2048,
                'responseMimeType' => $json ? 'application/json' : null,
            ])]
        );
        if ($response->failed()) throw new RuntimeException('AI provider request failed (' . $response->status() . '): ' . mb_substr((string) $response->body(), 0, 800));
        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
        if (!is_string($text) || trim($text) === '') throw new RuntimeException('AI provider returned an empty response.');
        return trim($text);
    }

    public function json(string $system, array $data, array $shape): array
    {
        $jsonInstruction = "\nReturn one valid JSON object only. No markdown, no code fences, and no explanation. Required JSON shape: " . json_encode($shape, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $lastRaw = '';
        $lastError = null;
        $models = array_values(array_unique(array_merge(
            [config('ai.gemini_model', 'gemini-3.7-flash')],
            config('ai.gemini_fallback_models', [])
        )));
        // First use Gemini's JSON MIME mode; then fall back to plain text because
        // some deployed Gemini model aliases reject responseMimeType.
        foreach ($models as $model) foreach ([true, false] as $jsonMode) {
            try {
                $raw = $this->call($system . $jsonInstruction . ($lastRaw ? "\nYour previous response was invalid JSON. Recompute and output only the object." : ''), $data, $jsonMode, $model);
                $lastRaw = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $raw));
                $decoded = json_decode($lastRaw, true);
                if (is_array($decoded)) return $decoded;

                // Recover a JSON object if the provider added a short prefix/suffix.
                $start = strpos($lastRaw, '{');
                $end = strrpos($lastRaw, '}');
                if ($start !== false && $end !== false && $end > $start) {
                    $decoded = json_decode(substr($lastRaw, $start, $end - $start + 1), true);
                    if (is_array($decoded)) return $decoded;
                }
            } catch (\Throwable $exception) {
                $lastError = $exception;
                // Continue to the next mode/model on transient provider overload.
                // Non-transient errors are also allowed to try the configured fallback.
            }
        }

        if ($lastError && $lastRaw === '') throw new RuntimeException('AI planner request failed: ' . $lastError->getMessage(), 0, $lastError);
        throw new RuntimeException('AI planner returned invalid JSON: ' . mb_substr($lastRaw, 0, 500));
    }

    public function text(string $system, array $data): string
    {
        $lastError = null;
        $models = array_values(array_unique(array_merge(
            [config('ai.gemini_model', 'gemini-3.7-flash')],
            config('ai.gemini_fallback_models', [])
        )));

        foreach ($models as $model) {
            try {
                return $this->call($system, $data, false, $model);
            } catch (\Throwable $exception) {
                $lastError = $exception;
            }
        }

        throw new RuntimeException(
            'AI response request failed: ' . ($lastError?->getMessage() ?? 'No model is configured.'),
            0,
            $lastError
        );
    }
}
