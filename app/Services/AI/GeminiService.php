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
        $response = Http::timeout((int) config('ai.gemini_timeout', 30))->retry(2, 250)->post(
            'https://generativelanguage.googleapis.com/v1beta/models/' . config('ai.gemini_model', 'gemini-2.0-flash') . ':generateContent?key=' . urlencode($key),
            ['contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]], 'generationConfig' => array_filter([
                'temperature' => 0.1,
                'responseMimeType' => $json ? 'application/json' : null,
            ])]
        );
        if ($response->failed()) throw new RuntimeException('AI provider request failed.');
        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
        if (!is_string($text) || trim($text) === '') throw new RuntimeException('AI provider returned an empty response.');
        return trim($text);
    }

    public function json(string $system, array $data, array $shape): array
    {
        $decoded = json_decode($this->call($system . "\nRequired JSON shape: " . json_encode($shape), $data, true), true);
        if (!is_array($decoded)) throw new RuntimeException('AI planner returned invalid JSON.');
        return $decoded;
    }

    public function text(string $system, array $data): string { return $this->call($system, $data, false); }
}
