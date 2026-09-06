<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('revenues', function (Blueprint $table) {
            $table->foreignId('treasury_id')->nullable()->after('reference_number')->constrained('treasuries')->nullOnDelete();
            $table->foreignId('currency_id')->nullable()->after('treasury_id')->constrained('currencies')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->after('currency_id')->constrained('branches')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('revenues', function (Blueprint $table) {
            $table->dropForeign(['treasury_id']);
            $table->dropForeign(['currency_id']);
            $table->dropForeign(['branch_id']);
            $table->dropColumn(['treasury_id', 'currency_id', 'branch_id']);
        });
    }
};