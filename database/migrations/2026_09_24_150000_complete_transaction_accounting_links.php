<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $links = [
            'sales_invoice_payments' => ['journal_entry_id'],
            'employee_advances' => ['journal_entry_id'],
            'employee_advance_payments' => ['journal_entry_id'],
            'employee_payrolls' => ['journal_entry_id', 'payment_journal_entry_id'],
            'employee_financial_transactions' => ['journal_entry_id'],
            'sales_invoices' => ['commission_journal_entry_id'],
            'sales_invoice_returns' => ['cogs_journal_entry_id'],
        ];

        foreach ($links as $table => $columns) {
            if (!Schema::hasTable($table)) continue;
            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns): void {
                foreach ($columns as $column) {
                    if (!Schema::hasColumn($table, $column)) {
                        $blueprint->foreignId($column)->nullable()->constrained('journal_entries')->nullOnDelete();
                    }
                }
            });
        }

        $this->linkExistingCashAccounts('treasuries', 'TREASURY', 'treasury');
        $this->linkExistingCashAccounts('banks', 'BANK', 'asset');
    }

    private function linkExistingCashAccounts(string $table, string $prefix, string $accountType): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'account_id') || !Schema::hasTable('accounts')) return;

        foreach (DB::table($table)->whereNull('account_id')->orderBy('id')->get() as $entity) {
            $code = $prefix . '-' . $entity->id;
            $account = DB::table('accounts')->where('code', $code)->first();
            if (!$account) {
                $name = $entity->name ?? ($prefix . ' ' . $entity->id);
                $values = [
                    'code' => $code,
                    'name' => $name,
                    'name_ar' => $name,
                    'account_type' => $accountType,
                    'normal_balance' => 'debit',
                    'is_header' => false,
                    'is_active' => true,
                    'debit' => round((float) ($entity->balance ?? 0), 2),
                    'credit' => 0,
                    'balance' => round((float) ($entity->balance ?? 0), 2),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                if (Schema::hasColumn('accounts', 'tenant_id') && isset($entity->tenant_id)) $values['tenant_id'] = $entity->tenant_id;
                $accountId = DB::table('accounts')->insertGetId($values);
            } else {
                $accountId = $account->id;
                $hasLines = Schema::hasTable('journal_entry_lines') && DB::table('journal_entry_lines')->where('account_id', $accountId)->exists();
                if (!$hasLines && (float) ($account->debit ?? 0) === 0.0 && (float) ($account->credit ?? 0) === 0.0 && (float) ($entity->balance ?? 0) > 0) {
                    DB::table('accounts')->where('id', $accountId)->update([
                        'debit' => round((float) $entity->balance, 2),
                        'balance' => round((float) $entity->balance, 2),
                    ]);
                }
            }
            DB::table($table)->where('id', $entity->id)->update(['account_id' => $accountId]);
        }
    }

    public function down(): void
    {
        // Posted financial data and linked accounts must remain intact on rollback.
    }
};
