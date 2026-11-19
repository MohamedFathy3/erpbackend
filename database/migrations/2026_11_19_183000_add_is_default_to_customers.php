<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('customers') || Schema::hasColumn('customers', 'is_default')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false)->after('active');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('customers') || !Schema::hasColumn('customers', 'is_default')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('is_default');
        });
    }
};
