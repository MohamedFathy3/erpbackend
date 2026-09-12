<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\DB;

class ReadOnlyQueryService
{
    public function run(string $sql, array $parameters): array
    {
        $started = microtime(true);
        [$sql, $bindings] = $this->expandRepeatedNamedBindings($sql, $parameters);
        $rows = DB::connection(config('ai.database_connection', 'ai_readonly'))->select($sql, $bindings);
        $max = (int) config('ai.max_rows', 100);
        return ['rows' => array_map(fn ($row) => (array) $row, array_slice($rows, 0, $max)), 'duration_ms' => (int) round((microtime(true) - $started) * 1000)];
    }

    private function expandRepeatedNamedBindings(string $sql, array $parameters): array
    {
        $occurrences = [];
        $bindings = [];
        $expandedSql = preg_replace_callback(
            '/(?<!:):([a-zA-Z_][a-zA-Z0-9_]*)\b/',
            function (array $match) use (&$occurrences, &$bindings, $parameters): string {
                $name = $match[1];
                if (!array_key_exists($name, $parameters)) return $match[0];

                $occurrences[$name] = ($occurrences[$name] ?? 0) + 1;
                $bindingName = $occurrences[$name] === 1 ? $name : $name . '_' . $occurrences[$name];
                $bindings[$bindingName] = $parameters[$name];
                return ':' . $bindingName;
            },
            $sql
        );

        return [$expandedSql ?? $sql, $bindings];
    }
}
