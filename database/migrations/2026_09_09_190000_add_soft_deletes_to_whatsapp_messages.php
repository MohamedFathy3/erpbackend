<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_messages') && !Schema::hasColumn('whatsapp_messages', 'deleted_at')) {
            Schema::table('whatsapp_messages', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('whatsapp_messages') && Schema::hasColumn('whatsapp_messages', 'deleted_at')) {
            Schema::table('whatsapp_messages', function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }
};
