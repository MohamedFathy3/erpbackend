<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('automotive_visits') && !Schema::hasColumn('automotive_visits', 'deleted_at')) {
            Schema::table('automotive_visits', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('automotive_visits') && Schema::hasColumn('automotive_visits', 'deleted_at')) {
            Schema::table('automotive_visits', function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }
};
