<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('account_type')->nullable()->after('code');
        });
    }

    public function down()
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('account_type');
        });
    }
};