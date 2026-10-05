<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices') && !Schema::hasColumn('invoices', 'extra_charge')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->decimal('extra_charge', 12, 2)->default(0)->after('discount_amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'extra_charge')) {
            Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('extra_charge'));
        }
    }
};
