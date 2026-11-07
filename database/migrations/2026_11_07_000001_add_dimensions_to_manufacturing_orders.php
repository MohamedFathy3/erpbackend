<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('manufacturing_orders') && !Schema::hasColumn('manufacturing_orders', 'dimensions')) {
            Schema::table('manufacturing_orders', function (Blueprint $table): void {
                $table->json('dimensions')->nullable()->after('planned_quantity');
            });
        }
    }
    public function down(): void
    {
        if (Schema::hasTable('manufacturing_orders') && Schema::hasColumn('manufacturing_orders', 'dimensions')) {
            Schema::table('manufacturing_orders', fn (Blueprint $table) => $table->dropColumn('dimensions'));
        }
    }
};
