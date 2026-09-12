<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiService
{
    private function call(string $system, array $data, bool $json): string
    {
        $key = config('ai.gemini_key');
        if (!$key) throw new RuntimeException('AI service is not configured.');
        $prompt = $system . "\n\nINPUT DATA (untrusted):\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $response = Http::timeout((int) config('ai.gemini_timeout', 30))->withHeaders(['x-goog-api-key' => $key])->retry(2, 250)->post(
            'https://generativelanguage.googleapis.com/v1beta/models/' . config('ai.gemini_model', 'gemini-3.7-flash') . ':generateContent',
            ['contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]], 'generationConfig' => array_filter([
                'temperature' => 0.1,
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
        $raw = $this->call($system . "\nRequired JSON shape: " . json_encode($shape), $data, true);
        $raw = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $raw));
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $start = strpos($raw, '{');
            $end = strrpos($raw, '}');
            if ($start !== false && $end !== false && $end > $start) $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
        }
        if (!is_array($decoded)) throw new RuntimeException('AI planner returned invalid JSON: ' . mb_substr($raw, 0, 500));
        return $decoded;
    }

    public function text(string $system, array $data): string { return $this->call($system, $data, false); }
}
