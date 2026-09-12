<?php

namespace Tests\Unit;

use App\Services\AI\SQLValidatorService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SQLValidatorServiceTest extends TestCase
{
    private function schema(): array
    {
        return ['tables' => [
            ['table' => 'customers', 'columns' => [['name' => 'id'], ['name' => 'tenant_id'], ['name' => 'name']]],
            ['table' => 'products', 'columns' => [['name' => 'id'], ['name' => 'name']]],
        ]];
    }

    public function test_accepts_parameterized_select_and_caps_limit(): void
    {
        $sql = (new SQLValidatorService())->validate('SELECT id, name FROM customers WHERE tenant_id = :tenant_id AND name LIKE :name LIMIT 100000', ['tenant_id' => 7, 'name' => '%A%'], $this->schema(), 7);
        $this->assertStringContainsString('LIMIT 100', $sql);
    }

    public function test_rejects_write_statements_and_comments(): void
    {
        $this->expectException(RuntimeException::class);
        (new SQLValidatorService())->validate('SELECT * FROM customers; DELETE FROM customers', [], $this->schema());
    }

    public function test_requires_tenant_filter_for_tenant_tables(): void
    {
        $this->expectException(RuntimeException::class);
        (new SQLValidatorService())->validate('SELECT * FROM customers', [], $this->schema(), 7);
    }
}
