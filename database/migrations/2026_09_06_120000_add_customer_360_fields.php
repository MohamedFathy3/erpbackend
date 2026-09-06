<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('customer_code')->nullable()->unique()->after('id');
            $table->string('tax_number')->nullable()->after('phone');
            $table->string('industry')->nullable()->after('tax_number');
            $table->decimal('credit_limit', 14, 2)->default(0)->after('industry');
            $table->string('payment_terms')->nullable()->after('credit_limit');
            $table->text('notes')->nullable()->after('payment_terms');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['customer_code']);
            $table->dropColumn([
                'customer_code', 'tax_number', 'industry', 'credit_limit',
                'payment_terms', 'notes',
            ]);
        });
    }
};
