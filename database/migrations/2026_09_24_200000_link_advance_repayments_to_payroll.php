<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('employee_advance_payments')) return;

        Schema::table('employee_advance_payments', function (Blueprint $table): void {
            if (!Schema::hasColumn('employee_advance_payments', 'source_payroll_id')) {
                $table->foreignId('source_payroll_id')->nullable()->constrained('employee_payrolls')->nullOnDelete();
                $table->unique(['advance_id', 'source_payroll_id'], 'advance_payroll_payment_unique');
            }
        });

        if (!Schema::hasTable('employee_payrolls') || !Schema::hasTable('employee_advances')) return;

        // Backfill only deductions with a posted payroll journal and only against outstanding advances.
        $payrolls = DB::table('employee_payrolls')
            ->whereNull('deleted_at')
            ->whereNotNull('journal_entry_id')
            ->where('advance_deductions', '>', 0)
            ->orderBy('period_end')
            ->orderBy('id')
            ->get();

        foreach ($payrolls as $payroll) {
            $remaining = min(
                (float) $payroll->advance_deductions,
                (float) $payroll->base_salary + (float) $payroll->allowances
            );
            if ($remaining <= 0) continue;

            $advances = DB::table('employee_advances')
                ->where('employee_id', $payroll->employee_id)
                ->whereNull('deleted_at')
                ->whereDate('advance_date', '<=', $payroll->period_end)
                ->orderBy('advance_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($advances as $advance) {
                $alreadyPaid = (float) DB::table('employee_advance_payments')
                    ->where('advance_id', $advance->id)
                    ->whereNull('deleted_at')
                    ->sum('amount');
                $available = max(0, (float) $advance->amount - max((float) $advance->paid_amount, $alreadyPaid));
                if ($available <= 0 || $remaining <= 0) continue;

                $allocated = min($available, $remaining);
                $exists = DB::table('employee_advance_payments')
                    ->where('advance_id', $advance->id)
                    ->where('source_payroll_id', $payroll->id)
                    ->exists();
                if (!$exists) {
                    DB::table('employee_advance_payments')->insert([
                        'advance_id' => $advance->id,
                        'source_payroll_id' => $payroll->id,
                        'amount' => $allocated,
                        'payment_date' => $payroll->paid_at ?: $payroll->period_end,
                        'recorded_by_name' => 'خصم سلفة من الراتب (ترحيل سابق)',
                        'notes' => 'استرداد تلقائي عبر قيد استحقاق الراتب #' . $payroll->id,
                        'journal_entry_id' => $payroll->journal_entry_id,
                        'tenant_id' => $payroll->tenant_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                $alreadyPaid += $allocated;
                $newPaid = min((float) $advance->amount, max((float) $advance->paid_amount, $alreadyPaid));
                DB::table('employee_advances')->where('id', $advance->id)->update([
                    'paid_amount' => $newPaid,
                    'last_payment_at' => $payroll->paid_at ?: $payroll->period_end,
                    'status' => $newPaid >= (float) $advance->amount ? 'paid' : 'repaying',
                    'updated_at' => now(),
                ]);
                $remaining -= $allocated;
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('employee_advance_payments') || !Schema::hasColumn('employee_advance_payments', 'source_payroll_id')) return;
        DB::table('employee_advance_payments')->whereNotNull('source_payroll_id')->delete();
        Schema::table('employee_advance_payments', function (Blueprint $table): void {
            $table->dropUnique('advance_payroll_payment_unique');
            $table->dropConstrainedForeignId('source_payroll_id');
        });
    }
};
