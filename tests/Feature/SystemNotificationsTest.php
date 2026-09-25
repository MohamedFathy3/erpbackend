<?php

namespace Tests\Feature;

use App\Http\Controllers\InventoryTransferRequestController;
use App\Http\Controllers\ProductLedgerController;
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
