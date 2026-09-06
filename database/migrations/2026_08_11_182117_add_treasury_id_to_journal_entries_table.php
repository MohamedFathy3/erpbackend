<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('treasury_id')
                ->nullable()
                ->after('status')
                ->constrained('treasuries')
                ->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropForeign(['treasury_id']);
            $table->dropColumn('treasury_id');
        });
    }
};