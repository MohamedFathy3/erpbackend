<?php

namespace App\Services\AI;

class AIResponseService
{
    public function __construct(private readonly GeminiService $gemini) {}

    public function answer(string $question, array $rows, array $plan): string
    {
        return $this->answerSections($question, [[
            'intent' => $plan['intent'] ?? 'Report',
            'rows' => $rows,
        ]]);
    }

    public function answerSections(string $question, array $sections): string
    {
        // The rows are already computed. Calling Gemini again only to format
        // them adds latency and can turn a successful query into a timeout.
        return collect($sections)->map(function (array $section): string {
            $intent = trim((string) ($section['intent'] ?? 'Report')) ?: 'Report';
            $rows = $section['rows'] ?? [];
            $message = !$rows
                ? 'No matching records were found for this section.'
                : (count($rows) === 1 ? '1 matching record found.' : count($rows) . ' matching records found.');
            return "### {$intent}\n{$message}";
        })->implode("\n\n");
    }
}
