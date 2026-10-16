<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finances', function (Blueprint $table): void {
            $table->string('source_key')->nullable()->unique();
            $table->boolean('is_tracking_only')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('finances', function (Blueprint $table): void {
            $table->dropUnique(['source_key']);
            $table->dropColumn(['source_key', 'is_tracking_only']);
        });
    }
};
