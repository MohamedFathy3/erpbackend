<?php

namespace Tests\Feature;

use App\Http\Controllers\InventoryTransferRequestController;
use App\Http\Controllers\ProductLedgerController;
use App\Http\Controllers\ProductController;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Finance;
use App\Models\InventoryTransferRequest;
use App\Models\Product;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Models\Warehouse;
use App\Notifications\SystemEventNotification;
use App\Services\AccountingAutoPostingService;
use App\Services\InventoryMovementService;
use App\Services\InventoryTransferPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SystemNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_pos_hides_transfer_product_until_destination_stock_arrives(): void
    {
        $tenant = Tenant::create(['name' => 'Branch catalog tenant', 'slug' => 'branch-catalog-test']);
        $otherTenant = Tenant::create(['name' => 'Other catalog tenant', 'slug' => 'other-branch-catalog-test']);
        $adminRole = Role::create(['name' => 'Admin']);
        $sourceBranch = Branch::create(['name' => 'Source', 'tenant_id' => $tenant->id]);
        $destinationBranch = Branch::create(['name' => 'Destination', 'tenant_id' => $tenant->id]);
        $otherBranch = Branch::create(['name' => 'Other tenant branch', 'tenant_id' => $otherTenant->id]);
        $sourceWarehouse = Warehouse::create(['name' => 'Source warehouse', 'branch_id' => $sourceBranch->id, 'tenant_id' => $tenant->id]);
        $destinationWarehouse = Warehouse::create(['name' => 'Destination warehouse', 'branch_id' => $destinationBranch->id, 'tenant_id' => $tenant->id]);
        $otherWarehouse = Warehouse::create(['name' => 'Other warehouse', 'branch_id' => $otherBranch->id, 'tenant_id' => $otherTenant->id]);
        $employee = Employee::create([
            'name' => 'Destination cashier',
            'role_id' => $adminRole->id,
            'branch_id' => $destinationBranch->id,
            'tenant_id' => $tenant->id,
        ]);
        $product = Product::create([
            'name' => 'Source-only product',
            'stock' => 5,
            'branch_id' => $sourceBranch->id,
            'tenant_id' => $tenant->id,
        ]);
        $otherProduct = Product::create([
            'name' => 'Foreign-tenant product',
            'stock' => 9,
            'branch_id' => $otherBranch->id,
            'tenant_id' => $otherTenant->id,
        ]);
        foreach ([[$product, $sourceWarehouse, 5], [$product, $destinationWarehouse, 0], [$otherProduct, $otherWarehouse, 9]] as [$linkedProduct, $warehouse, $stock]) {
            \Illuminate\Support\Facades\DB::table('product_warehouse')->insert([
                'product_id' => $linkedProduct->id,
                'warehouse_id' => $warehouse->id,
                'stock' => $stock,
                'cost' => 4,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->actingAs($employee);
        $catalogRequest = Request::create('/api/products/by-branch', 'POST', ['branch_id' => $destinationBranch->id]);
        $catalogRequest->setUserResolver(fn () => $employee);
        $catalog = app(ProductController::class)->getProductsByBranch($catalogRequest)->response()->getData(true)['data'];
        $this->assertCount(1, $catalog);
        $this->assertSame($product->id, $catalog[0]['id']);
        $this->assertSame(0.0, (float) $catalog[0]['stock']);

        $branchesRequest = Request::create('/api/inventory-transfer-requests/branches', 'GET');
        $branchesRequest->setUserResolver(fn () => $employee);
        $branches = app(InventoryTransferRequestController::class)->branches($branchesRequest)->getData(true)['data'];
        $this->assertSame([$sourceBranch->id], array_column($branches, 'id'));

        $sourceProductsRequest = Request::create('/api/inventory-transfer-requests/products', 'POST', [
            'source_branch_id' => $sourceBranch->id,
        ]);
        $sourceProductsRequest->setUserResolver(fn () => $employee);
        $sourceProducts = app(InventoryTransferRequestController::class)->products($sourceProductsRequest)->getData(true)['data'];
        $this->assertCount(1, $sourceProducts);
        $this->assertSame($product->id, $sourceProducts[0]['id']);
        $this->assertSame(5.0, (float) $sourceProducts[0]['stock']);

        \Illuminate\Support\Facades\DB::table('product_warehouse')
            ->where('product_id', $product->id)
            ->where('warehouse_id', $destinationWarehouse->id)
            ->update(['stock' => 2]);
        $arrivedCatalog = app(ProductController::class)->getProductsByBranch($catalogRequest)->response()->getData(true)['data'];
        $this->assertSame(2.0, (float) $arrivedCatalog[0]['stock']);
    }

    public function test_transfer_request_works_without_employees_is_active_column_and_notifies_tenant_admin(): void
    {
        Notification::fake();
        $tenant = Tenant::create(['name' => 'Notification tenant', 'slug' => 'notify-test']);
        $admin = Admin::factory()->create(['tenant_id' => $tenant->id]);
        $role = Role::create(['name' => 'Admin']);
        $sourceBranch = Branch::create(['name' => 'Source', 'tenant_id' => $tenant->id]);
        $destinationBranch = Branch::create(['name' => 'Destination', 'tenant_id' => $tenant->id]);
        $sourceWarehouse = Warehouse::create(['name' => 'Source warehouse', 'branch_id' => $sourceBranch->id, 'tenant_id' => $tenant->id]);
        $destinationWarehouse = Warehouse::create(['name' => 'Destination warehouse', 'branch_id' => $destinationBranch->id, 'tenant_id' => $tenant->id]);
        $employee = Employee::create([
            'name' => 'Branch manager',
            'role_id' => $role->id,
            'branch_id' => $destinationBranch->id,
            'tenant_id' => $tenant->id,
        ]);
        $product = Product::create([
            'name' => 'Test product',
            'stock' => 10,
            'cost' => 8,
            'reorder_level' => 2,
            'branch_id' => $sourceBranch->id,
            'tenant_id' => $tenant->id,
        ]);
        \Illuminate\Support\Facades\DB::table('product_warehouse')->insert([
            'product_id' => $product->id,
            'warehouse_id' => $sourceWarehouse->id,
            'stock' => 10,
            'cost' => 8,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($employee);
        $request = Request::create('/api/inventory-transfer-requests', 'POST', [
            'product_id' => $product->id,
            'from_branch_id' => $sourceBranch->id,
            'from_warehouse_id' => $sourceWarehouse->id,
            'to_warehouse_id' => $destinationWarehouse->id,
            'quantity' => 2,
            'note' => 'Test request',
        ]);
        $request->setUserResolver(fn () => $employee);

        $response = app(InventoryTransferRequestController::class)->store($request);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame($product->id, $response->getData(true)['data']['product']['id']);
        $this->assertSame($sourceWarehouse->id, $response->getData(true)['data']['from_warehouse']['id']);
        $this->assertDatabaseHas('inventory_transfer_requests', [
            'product_id' => $product->id,
            'from_warehouse_id' => $sourceWarehouse->id,
            'to_warehouse_id' => $destinationWarehouse->id,
            'status' => 'pending',
        ]);
        $transferId = (int) \Illuminate\Support\Facades\DB::table('inventory_transfer_requests')->value('id');

        $indexRequest = Request::create('/api/inventory-transfer-requests', 'GET');
        $indexRequest->setUserResolver(fn () => $employee);
        $indexResponse = app(InventoryTransferRequestController::class)->index($indexRequest);
        $this->assertSame(200, $indexResponse->getStatusCode());
        $this->assertSame($transferId, $indexResponse->getData(true)['data']['data'][0]['id']);

        $approveRequest = Request::create('/api/inventory-transfer-requests/' . $transferId . '/approve', 'POST', []);
        $approveRequest->setUserResolver(fn () => $employee);
        $approveResponse = app(InventoryTransferRequestController::class)->approve(
            $approveRequest,
            InventoryTransferRequest::query()->findOrFail($transferId),
        );
        $this->assertSame(200, $approveResponse->getStatusCode());
        $this->assertDatabaseHas('inventory_transfer_requests', [
            'id' => $transferId,
            'status' => 'approved',
        ]);
        $this->assertNotNull(InventoryTransferRequest::query()->findOrFail($transferId)->journal_entry_id);
        $this->assertSame(8.0, (float) \Illuminate\Support\Facades\DB::table('product_warehouse')->where('product_id', $product->id)->where('warehouse_id', $sourceWarehouse->id)->value('stock'));
        $this->assertSame(2.0, (float) \Illuminate\Support\Facades\DB::table('product_warehouse')->where('product_id', $product->id)->where('warehouse_id', $destinationWarehouse->id)->value('stock'));
        $this->assertSame(8.0, (float) \Illuminate\Support\Facades\DB::table('product_warehouse')->where('product_id', $product->id)->where('warehouse_id', $destinationWarehouse->id)->value('cost'));
        $this->assertSame(10.0, (float) $product->fresh()->stock);
        $this->assertDatabaseHas('inventory_movements', [
            'reference_type' => InventoryTransferRequest::class,
            'reference_id' => $transferId,
            'movement_type' => 'branch_transfer_out',
            'quantity_delta' => -2,
            'total_cost' => 16,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'reference_type' => InventoryTransferRequest::class,
            'reference_id' => $transferId,
            'movement_type' => 'branch_transfer_in',
            'quantity_delta' => 2,
            'total_cost' => 16,
        ]);
        $journalId = (int) InventoryTransferRequest::query()->findOrFail($transferId)->journal_entry_id;
        $this->assertSame(16.0, (float) \Illuminate\Support\Facades\DB::table('journal_entry_lines')->where('journal_entry_id', $journalId)->sum('debit'));
        $this->assertSame(16.0, (float) \Illuminate\Support\Facades\DB::table('journal_entry_lines')->where('journal_entry_id', $journalId)->sum('credit'));
        $ledgerResponse = app(ProductLedgerController::class)->show(Request::create('/api/product/' . $product->id . '/ledger', 'GET'), $product->fresh());
        $ledgerMovements = $ledgerResponse->getData(true)['data']['movements'];
        $this->assertCount(2, $ledgerMovements);
        $this->assertEqualsCanonicalizing(['branch_transfer_in', 'branch_transfer_out'], array_column($ledgerMovements, 'type'));
        $this->assertEqualsCanonicalizing([$sourceBranch->id, $destinationBranch->id], array_column(array_column($ledgerMovements, 'branch'), 'id'));
        Notification::assertSentTo($admin, SystemEventNotification::class, fn (SystemEventNotification $notification) => $notification->category === 'warning');
    }

    public function test_crossing_product_reorder_level_notifies_tenant_admin(): void
    {
        Notification::fake();
        $tenant = Tenant::create(['name' => 'Stock notification tenant', 'slug' => 'stock-notify-test']);
        $admin = Admin::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::create([
            'name' => 'Low stock product',
            'stock' => 6,
            'reorder_level' => 5,
            'tenant_id' => $tenant->id,
        ]);

        app(InventoryMovementService::class)->apply([
            'product_id' => $product->id,
            'movement_type' => 'sale',
            'quantity_delta' => -1,
            'notes' => 'Cross reorder threshold test',
        ]);

        Notification::assertSentTo($admin, SystemEventNotification::class, fn (SystemEventNotification $notification) => $notification->eventKey === 'low-stock:' . $product->inventoryMovements()->latest('id')->value('id'));
        $this->assertSame(5.0, (float) $product->fresh()->stock);
    }

    public function test_posted_expense_journal_notifies_tenant_admin(): void
    {
        Notification::fake();
        $tenant = Tenant::create(['name' => 'Journal notification tenant', 'slug' => 'journal-notify-test']);
        $admin = Admin::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($admin);
        $expense = Finance::create([
            'category' => 'utilities',
            'amount' => 45,
            'description' => 'Notification test expense',
            'date' => '2026-09-25',
            'payment_method' => 'other',
            'tenant_id' => $tenant->id,
        ]);

        $journal = app(AccountingAutoPostingService::class)->postFinance($expense);

        Notification::assertSentTo($admin, SystemEventNotification::class, fn (SystemEventNotification $notification) => $notification->eventKey === 'journal-posted:' . $journal->id);
    }

    public function test_treasury_crossing_configured_minimum_balance_notifies_tenant_admin(): void
    {
        Notification::fake();
        $tenant = Tenant::create(['name' => 'Treasury notification tenant', 'slug' => 'treasury-notify-test']);
        $admin = Admin::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($admin);
        $treasury = Treasury::create([
            'name' => 'Operations cash',
            'balance' => 5,
            'alert_below_balance' => 10,
            'currency' => 'EGP',
            'tenant_id' => $tenant->id,
        ]);
        $transaction = TreasuryTransaction::create([
            'treasury_id' => $treasury->id,
            'type' => 'out',
            'amount' => 20,
            'description' => 'Cross minimum balance test',
            'tenant_id' => $tenant->id,
        ]);

        Notification::assertSentTo($admin, SystemEventNotification::class, fn (SystemEventNotification $notification) => $notification->eventKey === 'treasury-low-balance:' . $transaction->id && $notification->category === 'warning');
    }

    public function test_treasury_uses_automatic_ten_percent_alert_threshold_when_no_custom_limit_is_set(): void
    {
        Notification::fake();
        $tenant = Tenant::create(['name' => 'Automatic threshold tenant', 'slug' => 'automatic-threshold-test']);
        $admin = Admin::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($admin);
        $treasury = Treasury::create([
            'name' => 'Automatic operations cash',
            'balance' => 500,
            'currency' => 'EGP',
            'tenant_id' => $tenant->id,
        ]);
        $treasury->update(['balance' => 40]);
        $transaction = TreasuryTransaction::create([
            'treasury_id' => $treasury->id,
            'type' => 'out',
            'amount' => 460,
            'description' => 'Cross automatic minimum balance test',
            'tenant_id' => $tenant->id,
        ]);

        $this->assertSame(50.0, (float) $treasury->fresh()->alert_below_balance);
        Notification::assertSentTo($admin, SystemEventNotification::class, fn (SystemEventNotification $notification) => $notification->eventKey === 'treasury-low-balance:' . $transaction->id);
    }
}
