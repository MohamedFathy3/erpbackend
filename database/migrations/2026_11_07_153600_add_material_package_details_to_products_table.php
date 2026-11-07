<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (!Schema::hasColumn('products', 'package_size')) {
                $table->decimal('package_size', 18, 6)->nullable();
            }
            if (!Schema::hasColumn('products', 'package_unit')) {
                $table->string('package_unit', 32)->nullable();
            }
            if (!Schema::hasColumn('products', 'package_cost')) {
                $table->decimal('package_cost', 12, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            foreach (['package_size', 'package_unit', 'package_cost'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
