<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_representatives', function (Blueprint $table) {
            if (!Schema::hasColumn('sales_representatives', 'password')) {
                $table->string('password')->nullable()->after('email');
            }
            if (!Schema::hasColumn('sales_representatives', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_representatives', function (Blueprint $table) {
            foreach (['password', 'last_login_at'] as $column) {
                if (Schema::hasColumn('sales_representatives', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
