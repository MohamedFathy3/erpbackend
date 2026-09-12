<?php

namespace App\Console\Commands;

use App\Services\AI\GeminiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class TestGeminiConnection extends Command
{
    protected $signature = 'ai:test-gemini {--database : Test the AI read-only database connection instead}';
    protected $description = 'Test Gemini or the AI read-only database connection';

    public function handle(GeminiService $gemini): int
    {
        if ($this->option('database')) return $this->testDatabase();
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

    private function testDatabase(): int
    {
        $connection = config('ai.database_connection', 'ai_readonly');
        $this->line('Connection: ' . $connection);
        $this->line('Driver: ' . config('database.connections.' . $connection . '.driver'));
        $this->line('Database: ' . config('database.connections.' . $connection . '.database'));
        try {
            DB::connection($connection)->select('SELECT 1 AS connection_ok');
            $this->info('SELECT 1 succeeded.');
            $tables = Schema::connection($connection)->getTables();
            $this->info('Schema access succeeded. Tables found: ' . count($tables));
            $this->line('Sample tables: ' . implode(', ', array_slice(array_map(fn ($table) => $table['name'] ?? '?', $tables), 0, 10)));
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('AI database test failed: ' . get_class($exception));
            $this->line($exception->getMessage());
            return self::FAILURE;
        }
    }
}
