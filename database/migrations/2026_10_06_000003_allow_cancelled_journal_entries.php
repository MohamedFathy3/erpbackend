<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('journal_entries') || !Schema::hasColumn('journal_entries', 'status')) {
            return;
        }

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft')->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('journal_entries') || !Schema::hasColumn('journal_entries', 'status')) {
            return;
        }

        DB::table('journal_entries')->where('status', 'cancelled')->update(['status' => 'posted']);

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->enum('status', ['draft', 'posted'])->default('draft')->change();
        });
    }
};