<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colors', function (Blueprint $table) {
            $table->dropUnique('colors_name_unique');
            $table->dropUnique('colors_code_unique');
            $table->dropUnique('colors_hex_code_unique');

            $table->unique(['tenant_id', 'name'], 'colors_tenant_name_unique');
            $table->unique(['tenant_id', 'code'], 'colors_tenant_code_unique');
            $table->unique(['tenant_id', 'hex_code'], 'colors_tenant_hex_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('colors', function (Blueprint $table) {
            $table->dropUnique('colors_tenant_name_unique');
            $table->dropUnique('colors_tenant_code_unique');
            $table->dropUnique('colors_tenant_hex_code_unique');

            $table->unique('name', 'colors_name_unique');
            $table->unique('code', 'colors_code_unique');
            $table->unique('hex_code', 'colors_hex_code_unique');
        });
    }
};