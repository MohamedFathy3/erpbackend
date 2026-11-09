<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('whatsapp_accounts')) return;
        Schema::table('whatsapp_accounts', function (Blueprint $table): void {
            if (!Schema::hasColumn('whatsapp_accounts', 'metadata')) $table->json('metadata')->nullable();
            if (!Schema::hasColumn('whatsapp_accounts', 'register_pin')) $table->text('register_pin')->nullable();
            if (!Schema::hasColumn('whatsapp_accounts', 'setup_method')) $table->string('setup_method', 32)->nullable();
        });
    }
    public function down(): void
    {
        if (!Schema::hasTable('whatsapp_accounts')) return;
        Schema::table('whatsapp_accounts', function (Blueprint $table): void {
            foreach (['metadata', 'register_pin', 'setup_method'] as $column) {
                if (Schema::hasColumn('whatsapp_accounts', $column)) $table->dropColumn($column);
            }
        });
    }
};
