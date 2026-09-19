<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('automotive_customer_accounts') && !Schema::hasColumn('automotive_customer_accounts', 'deleted_at')) {
            Schema::table('automotive_customer_accounts', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('automotive_customer_accounts') && Schema::hasColumn('automotive_customer_accounts', 'deleted_at')) {
            Schema::table('automotive_customer_accounts', function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }
};
