<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finances', function (Blueprint $table) {
            // لو مش عاوز تتأكد بوجود العمود، ممكن تشيل الـ if وتخليها مباشرة
            if (!Schema::hasColumn('finances', 'treasury_id')) {
                $table->foreignId('treasury_id')->nullable()->constrained('treasuries')->onDelete('set null');
            }
            
            if (!Schema::hasColumn('finances', 'currency_id')) {
                $table->foreignId('currency_id')->nullable()->constrained('currencies')->onDelete('set null');
            }
            
            if (!Schema::hasColumn('finances', 'branch_id')) {
                $table->foreignId('branch_id')->nullable()->constrained('branches')->onDelete('set null');
            }
        });
    }

    public function down(): void
    {
        Schema::table('finances', function (Blueprint $table) {
            $table->dropForeign(['treasury_id']);
            $table->dropForeign(['currency_id']);
            $table->dropForeign(['branch_id']);
            $table->dropColumn(['treasury_id', 'currency_id', 'branch_id']);
        });
    }
};