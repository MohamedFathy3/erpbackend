<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('return_invoices') || Schema::hasColumn('return_invoices', 'refund_treasury_id')) {
            return;
        }

        Schema::table('return_invoices', function (Blueprint $table): void {
            $table->foreignId('refund_treasury_id')
                ->nullable()
                ->after('refund_method')
                ->constrained('treasuries')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('return_invoices') || !Schema::hasColumn('return_invoices', 'refund_treasury_id')) {
            return;
        }

        Schema::table('return_invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('refund_treasury_id');
        });
    }
};
