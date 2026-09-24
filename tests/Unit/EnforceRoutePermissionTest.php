<?php

namespace Tests\Unit;

use App\Http\Middleware\EnforceRoutePermission;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class EnforceRoutePermissionTest extends TestCase
{
    private function permissionFor(string $method, string $path): ?string
    {
        $middleware = new EnforceRoutePermission();
        $methodRef = new ReflectionMethod($middleware, 'permissionFor');
        $methodRef->setAccessible(true);

        return $methodRef->invoke($middleware, Request::create($path, $method));
    }

    public function test_post_index_routes_require_view_permission(): void
    {
        $this->assertSame('currency.view', $this->permissionFor('POST', '/api/currency/index'));
        $this->assertSame('tax.view', $this->permissionFor('POST', '/api/tax/index'));
        $this->assertSame('purchasing.view', $this->permissionFor('POST', '/api/purchases-invoices/index'));
        $this->assertSame('inventory.view', $this->permissionFor('POST', '/api/products/by-branch'));
        $this->assertSame('finance.view', $this->permissionFor('GET', '/api/index-sub-account'));
    }

    public function test_plural_purchase_invoice_update_requires_purchasing_update(): void
    {
        $this->assertSame('purchasing.update', $this->permissionFor('PUT', '/api/purchases-invoices/update/54'));
        $this->assertSame('purchasing.update', $this->permissionFor('PUT', '/api/purchase-invoices/54'));
    }

    public function test_crud_methods_map_to_expected_permission_actions(): void
    {
        $this->assertSame('inventory.view', $this->permissionFor('GET', '/api/products/9'));
        $this->assertSame('inventory.create', $this->permissionFor('POST', '/api/products'));
        $this->assertSame('inventory.update', $this->permissionFor('PATCH', '/api/products/9'));
        $this->assertSame('inventory.delete', $this->permissionFor('DELETE', '/api/products/9'));
    }
}
