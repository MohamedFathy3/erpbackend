<?php

namespace App\Console\Commands;

use App\Services\AI\GeminiService;
use Illuminate\Console\Command;
use Throwable;

class TestGeminiConnection extends Command
{
    protected $signature = 'ai:test-gemini';
    protected $description = 'Test the configured Gemini API without accessing the database';

    public function handle(GeminiService $gemini): int
    {
        $this->info('Testing Gemini configuration and provider connectivity...');
        $this->line('Model: ' . config('ai.gemini_model'));

        try {
            $answer = $gemini->text(
                'Reply with exactly GEMINI_OK and nothing else. Do not expose secrets.',
                ['test' => 'connectivity check']
            );
            $this->info('Gemini accepted the request.');
            $this->line('Provider response: ' . $answer);
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Gemini test failed.');
            $this->line('Type: ' . get_class($exception));
            $this->line('Message: ' . $exception->getMessage());
            return self::FAILURE;
        }
    }
}
