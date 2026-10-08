<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices') && !Schema::hasColumn('invoices', 'cash_received_amount')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->decimal('cash_received_amount', 12, 2)->nullable()->after('paid_amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'cash_received_amount')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->dropColumn('cash_received_amount');
            });
        }
    }
};
