<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    // database/migrations/xxxx_add_has_fixed_price_to_automotive_services.php
public function up()
{
    Schema::table('automotive_services', function (Blueprint $table) {
        if (!Schema::hasColumn('automotive_services', 'has_fixed_price')) {
            $table->boolean('has_fixed_price')->default(true)->after('selling_price');
        }
    });
}

public function down()
{
    Schema::table('automotive_services', function (Blueprint $table) {
        $table->dropColumn('has_fixed_price');
    });
}
};
