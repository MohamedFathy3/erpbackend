<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('sales_invoice_returns', function (Blueprint $table) {
            $table->foreignId('sales_invoice_id')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('sales_invoice_returns', function (Blueprint $table) {
            $table->foreignId('sales_invoice_id')->nullable(false)->change();
        });
    }
};