<?php

namespace Tests\Feature;

use App\Http\Controllers\AccountingDocumentController;
use App\Models\Account;
use App\Models\AccountingDocument;
use App\Models\Employee;
use App\Models\FinancialPeriod;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class JournalEntryActorTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_is_stored_as_polymorphic_actor_not_as_user_foreign_key(): void
    {
        $tenant = Tenant::create(['name' => 'Actor test', 'slug' => 'actor-test']);
        $role = Role::create(['name' => 'cashier']);
        $employee = Employee::create([
            'name' => 'POS cashier',
            'role_id' => $role->id,
            'tenant_id' => $tenant->id,
        ]);
        $this->actingAs($employee);

        $entry = JournalEntry::create([
            'entry_date' => '2026-09-24',
            'description_ar' => 'اختبار قيد كاشير',
            'description_en' => 'Cashier journal test',
            'status' => 'posted',
            'posted_by' => $employee->id,
            'posted_at' => now(),
        ]);

        $this->assertNull($entry->posted_by);
        $this->assertSame(Employee::class, $entry->posted_by_type);
        $this->assertSame($employee->id, $entry->posted_by_id);
        $this->assertDatabaseHas('journal_entries', [
            'id' => $entry->id,
            'posted_by' => null,
            'posted_by_type' => Employee::class,
            'posted_by_id' => $employee->id,
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_employee_can_create_accounting_document_without_users_fk_violation(): void
    {
        $tenant = Tenant::create(['name' => 'Accounting actor test', 'slug' => 'accounting-actor-test']);
        $role = Role::create(['name' => 'accounting-clerk']);
        $employee = Employee::create(['name' => 'Accounting clerk', 'role_id' => $role->id, 'tenant_id' => $tenant->id]);
        $this->actingAs($employee);

        $period = FinancialPeriod::create([
            'code' => '2026-01',
            'name' => 'January 2026',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-01-31',
            'status' => 'open',
            'tenant_id' => $tenant->id,
        ]);
        $source = Account::create([
            'code' => 'ACTOR-SOURCE', 'name' => 'Source', 'account_type' => 'revenue',
            'normal_balance' => 'credit', 'is_header' => false, 'is_active' => true, 'tenant_id' => $tenant->id,
        ]);
        $destination = Account::create([
            'code' => 'ACTOR-DEST', 'name' => 'Destination', 'account_type' => 'asset',
            'normal_balance' => 'debit', 'is_header' => false, 'is_active' => true, 'tenant_id' => $tenant->id,
        ]);
        $request = Request::create('/api/accounting/documents', 'POST', [
            'document_type' => 'receipt',
            'document_date' => '2026-01-31',
            'amount' => 123,
            'source_account_id' => $source->id,
            'destination_account_id' => $destination->id,
            'reason' => 'Receipt from customer',
            'fiscal_period_id' => $period->id,
        ]);

        $response = app(AccountingDocumentController::class)->store($request, app(\App\Services\AccountLedgerService::class));
        $payload = $response->getData(true);
        $this->assertSame(201, $response->getStatusCode(), json_encode($payload, JSON_UNESCAPED_UNICODE));
        $document = AccountingDocument::findOrFail($payload['data']['id']);
        $entry = JournalEntry::findOrFail($document->journal_entry_id);
        $this->assertNull($entry->posted_by);
        $this->assertSame(Employee::class, $entry->posted_by_type);
        $this->assertSame($employee->id, $entry->posted_by_id);
        $this->assertNull($document->created_by);
        $this->assertSame(Employee::class, $document->created_by_type);
        $this->assertSame($employee->id, $document->created_by_id);
        $this->assertEquals(123, $destination->fresh()->debit);
        $this->assertEquals(123, $source->fresh()->credit);
    }
}
