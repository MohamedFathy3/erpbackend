<?php

namespace App\Services\AI;

use RuntimeException;

class SQLValidatorService
{
    private const FORBIDDEN = '/\b(insert|update|delete|drop|alter|truncate|create|rename|replace|merge|grant|revoke|call|exec|execute|load|outfile|dumpfile|set|use|show|describe|explain|into\s+outfile)\b/i';

    public function validate(?string $sql, array $parameters, array $schema, mixed $tenantId = null): string
    {
        if (!$sql) throw new RuntimeException('The requested information is not available in the database.');
        $sql = trim($sql);
        if (str_contains($sql, ';') || str_contains($sql, '--') || str_contains($sql, '/*') || str_contains($sql, '*/')) throw new RuntimeException('Only one safe read-only query is allowed.');
        if (!preg_match('/^(SELECT|WITH)\b/i', $sql) || preg_match(self::FORBIDDEN, $sql)) throw new RuntimeException('The generated query was rejected by the read-only security policy.');
        if (preg_match('/\b(pg_|information_schema|mysql|sqlite_|sys\.)/i', $sql)) throw new RuntimeException('System tables are not available.');
        $allowed = array_column($schema['tables'] ?? [], 'table');
        preg_match_all('/\b(?:from|join)\s+[`"]?([a-zA-Z_][a-zA-Z0-9_]*)/i', $sql, $matches);
        foreach ($matches[1] as $table) if (!in_array($table, $allowed, true)) throw new RuntimeException('The query references an unknown table.');
        $tenantTables = [];
        foreach ($schema['tables'] ?? [] as $table) if (in_array('tenant_id', array_column($table['columns'] ?? [], 'name'), true)) $tenantTables[] = $table['table'];
        if (array_intersect($matches[1], $tenantTables) && ($tenantId === null || !array_key_exists('tenant_id', $parameters) || !preg_match('/\btenant_id\s*=\s*:tenant_id\b/i', $sql))) throw new RuntimeException('Tenant isolation is required for this query.');
        foreach (array_keys($parameters) as $name) if ($name !== 'tenant_id' && !preg_match('/:' . preg_quote($name, '/') . '\b/', $sql)) throw new RuntimeException('A query parameter was not used safely.');
        preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $sql, $all);
        foreach (array_unique($all[1]) as $name) if (!array_key_exists($name, $parameters)) throw new RuntimeException('Missing query parameter.');
        $max = (int) config('ai.max_rows', 100);
        if (preg_match('/\bLIMIT\s+(\d+)/i', $sql, $limit)) {
            if ((int) $limit[1] > $max) $sql = preg_replace('/\bLIMIT\s+\d+/i', 'LIMIT ' . $max, $sql);
        } elseif (!preg_match('/\b(count|sum|avg|min|max)\s*\(/i', $sql)) {
            $sql .= ' LIMIT ' . $max;
        }
        return $sql;
    }
}
