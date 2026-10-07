<?php

namespace Tests\Feature;

use App\Http\Controllers\ProductController;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductIndexFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_index_filters_by_name_in_database(): void
    {
        $match = Product::create(['name' => 'same product', 'name_ar' => 'منتج سامح', 'sku' => 'SAME-1']);
        Product::create(['name' => 'different product', 'name_ar' => 'منتج آخر', 'sku' => 'OTHER-1']);

        $request = Request::create('/api/product/index', 'POST', [
            'filters' => ['name' => 'same'],
            'orderBy' => 'id',
            'orderByDirection' => 'desc',
            'perPage' => 20,
            'paginate' => false,
            'delete' => false,
        ]);

        $payload = app(ProductController::class)->index($request)->response()->getData(true);
        $rows = $payload['data'];

        $this->assertCount(1, $rows);
        $this->assertSame($match->id, $rows[0]['id']);
    }

    public function test_product_index_filters_by_arabic_name(): void
    {
        $match = Product::create(['name' => 'same product', 'name_ar' => 'صص']);
        Product::create(['name' => 'another product', 'name_ar' => 'منتج آخر']);

        $request = Request::create('/api/product/index', 'POST', [
            'filters' => ['name' => 'صص'],
            'paginate' => false,
        ]);

        $payload = app(ProductController::class)->index($request)->response()->getData(true);
        $rows = $payload['data'];

        $this->assertCount(1, $rows);
        $this->assertSame($match->id, $rows[0]['id']);
    }
}
