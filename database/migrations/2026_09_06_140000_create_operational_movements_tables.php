<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('manufacturing_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->enum('type', ['issue', 'receipt', 'transfer_in', 'transfer_out', 'adjustment']);
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('manufacturing_cost_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturing_order_id')->constrained()->cascadeOnDelete();
            $table->string('cost_type');
            $table->decimal('amount', 14, 2);
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('project_cost_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('cost_type');
            $table->decimal('amount', 14, 2);
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::table('manufacturing_orders', function (Blueprint $table) {
            $table->foreignId('completion_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
        });

        Schema::table('project_claims', function (Blueprint $table) {
            $table->foreignId('revenue_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('collection_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->decimal('paid_amount', 14, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('project_claims', function (Blueprint $table) {
            $table->dropForeign(['revenue_journal_entry_id']);
            $table->dropForeign(['collection_journal_entry_id']);
            $table->dropColumn(['revenue_journal_entry_id', 'collection_journal_entry_id', 'paid_amount']);
        });
        Schema::table('manufacturing_orders', function (Blueprint $table) {
            $table->dropForeign(['completion_journal_entry_id']);
            $table->dropColumn('completion_journal_entry_id');
        });
        Schema::dropIfExists('project_cost_entries');
        Schema::dropIfExists('manufacturing_cost_entries');
        Schema::dropIfExists('inventory_movements');
    }
};
