<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\DB;

class ReadOnlyQueryService
{
    public function run(string $sql, array $parameters): array
    {
        $started = microtime(true);
        $rows = DB::connection(config('ai.database_connection', 'ai_readonly'))->select($sql, $parameters);
        $max = (int) config('ai.max_rows', 100);
        return ['rows' => array_map(fn ($row) => (array) $row, array_slice($rows, 0, $max)), 'duration_ms' => (int) round((microtime(true) - $started) * 1000)];
    }
}
