<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class TestAIDatabaseConnection extends Command
{
    protected $signature = 'ai:test-database';
    protected $description = 'Test the AI read-only database connection and schema access';

    public function handle(): int
    {
        $connection = config('ai.database_connection', 'ai_readonly');
        $this->line('Connection: ' . $connection);
        $this->line('Driver: ' . config('database.connections.' . $connection . '.driver'));
        $this->line('Database: ' . config('database.connections.' . $connection . '.database'));

        try {
            DB::connection($connection)->select('SELECT 1 AS connection_ok');
            $this->info('SELECT 1 succeeded.');
        } catch (Throwable $exception) {
            $this->error('SELECT 1 failed: ' . get_class($exception));
            $this->line($exception->getMessage());
            return self::FAILURE;
        }

        try {
            $tables = Schema::connection($connection)->getTables();
            $this->info('Schema access succeeded. Tables found: ' . count($tables));
            $this->line('Sample tables: ' . implode(', ', array_slice(array_map(fn ($table) => $table['name'] ?? '?', $tables), 0, 10)));
        } catch (Throwable $exception) {
            $this->error('Schema access failed: ' . get_class($exception));
            $this->line($exception->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
