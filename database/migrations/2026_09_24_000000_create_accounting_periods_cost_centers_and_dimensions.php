<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('financial_periods')) Schema::create('financial_periods', function (Blueprint $table) {
            $table->id(); $table->string('code'); $table->string('name'); $table->date('starts_on'); $table->date('ends_on');
            $table->string('status')->default('open'); $table->boolean('is_current')->default(false); $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('closed_at')->nullable(); $table->text('close_notes')->nullable(); $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete(); $table->timestamps(); $table->softDeletes();
            $table->unique(['tenant_id','code'], 'period_tenant_code_unique'); $table->index(['tenant_id','starts_on','ends_on'], 'period_tenant_dates_idx');
        });
        if (!Schema::hasTable('cost_centers')) Schema::create('cost_centers', function (Blueprint $table) {
            $table->id(); $table->string('code'); $table->string('name'); $table->string('name_ar')->nullable(); $table->foreignId('parent_id')->nullable()->constrained('cost_centers')->nullOnDelete(); $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete(); $table->boolean('is_active')->default(true); $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete(); $table->timestamps(); $table->softDeletes();
            $table->unique(['tenant_id','code'], 'cost_center_tenant_code_unique');
        });
        if (Schema::hasTable('journal_entries')) Schema::table('journal_entries', function (Blueprint $table) {
            if (!Schema::hasColumn('journal_entries','entry_number')) $table->string('entry_number')->nullable()->index();
            if (!Schema::hasColumn('journal_entries','source_type')) $table->string('source_type')->nullable();
            if (!Schema::hasColumn('journal_entries','source_id')) $table->unsignedBigInteger('source_id')->nullable();
            if (!Schema::hasColumn('journal_entries','fiscal_period_id')) $table->foreignId('fiscal_period_id')->nullable()->constrained('financial_periods')->nullOnDelete();
            if (!Schema::hasColumn('journal_entries','cost_center_id')) $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            if (!Schema::hasColumn('journal_entries','branch_id')) $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            if (!Schema::hasColumn('journal_entries','posted_by')) $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            if (!Schema::hasColumn('journal_entries','posted_at')) $table->timestamp('posted_at')->nullable();
            if (!Schema::hasColumn('journal_entries','approved_by')) $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            if (!Schema::hasColumn('journal_entries','approved_at')) $table->timestamp('approved_at')->nullable();
            if (!Schema::hasColumn('journal_entries','reversal_of_id')) $table->foreignId('reversal_of_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            if (!Schema::hasColumn('journal_entries','reversal_reason')) $table->text('reversal_reason')->nullable();
            if (!Schema::hasColumn('journal_entries','created_by')) $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });
        if (Schema::hasTable('journal_entry_lines')) Schema::table('journal_entry_lines', function (Blueprint $table) {
            if (!Schema::hasColumn('journal_entry_lines','cost_center_id')) $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            if (!Schema::hasColumn('journal_entry_lines','branch_id')) $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            if (!Schema::hasColumn('journal_entry_lines','notes')) $table->text('notes')->nullable();
            $table->index(['account_id','journal_entry_id'], 'journal_line_account_entry_idx');
        });
    }
    public function down(): void {
        if (Schema::hasTable('journal_entry_lines')) Schema::table('journal_entry_lines', function (Blueprint $table) { if (Schema::hasColumn('journal_entry_lines','branch_id')) $table->dropConstrainedForeignId('branch_id'); if (Schema::hasColumn('journal_entry_lines','cost_center_id')) $table->dropConstrainedForeignId('cost_center_id'); if (Schema::hasColumn('journal_entry_lines','notes')) $table->dropColumn('notes'); });
        if (Schema::hasTable('journal_entries')) Schema::table('journal_entries', function (Blueprint $table) { foreach(['reversal_of_id','approved_by','posted_by','cost_center_id','fiscal_period_id'] as $column) if (Schema::hasColumn('journal_entries',$column)) $table->dropConstrainedForeignId($column); foreach(['created_by','entry_number','source_type','source_id','branch_id','posted_at','approved_at','reversal_reason'] as $column) if (Schema::hasColumn('journal_entries',$column)) $table->dropColumn($column); });
        Schema::dropIfExists('cost_centers'); Schema::dropIfExists('financial_periods');
    }
};
