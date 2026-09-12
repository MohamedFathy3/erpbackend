<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class DatabaseSchemaService
{
    public function getSchema(): array
    {
        $connection = config('ai.database_connection', 'ai_readonly');
        return Cache::remember('ai-schema:' . $connection, now()->addMinutes((int) config('ai.schema_cache_minutes', 10)), function () use ($connection) {
            $schema = [];
            foreach (Schema::connection($connection)->getTables() as $table) {
                $name = $table['name'] ?? null;
                if (!$name || str_starts_with($name, 'sqlite_') || in_array($name, ['migrations', 'telescope_entries'], true)) continue;
                $columns = [];
                foreach (Schema::connection($connection)->getColumns($name) as $column) {
                    $columns[] = ['name' => $column['name'], 'type' => $column['type_name'] ?? $column['type'], 'nullable' => (bool) ($column['nullable'] ?? false), 'primary' => (bool) ($column['auto_increment'] ?? false)];
                }
                $schema[] = ['table' => $name, 'columns' => $columns];
            }
            return ['connection' => $connection, 'tables' => $schema];
        });
    }

    public function compactForPrompt(): string
    {
        return json_encode($this->getSchema(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
