
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('product_warehouse', 'tenant_id')) {
            Schema::table('product_warehouse', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')
                    ->nullable()
                    ->after('id');

                $table->index('tenant_id', 'product_warehouse_tenant_id_index');
            });
        }

        // نقل tenant_id من المنتج إلى سجل المخزن
        DB::statement("
            UPDATE product_warehouse pw
            INNER JOIN products p ON p.id = pw.product_id
            SET pw.tenant_id = p.tenant_id
            WHERE pw.tenant_id IS NULL
        ");
    }

    public function down(): void
    {
        if (Schema::hasColumn('product_warehouse', 'tenant_id')) {
            Schema::table('product_warehouse', function (Blueprint $table) {
                $table->dropIndex('product_warehouse_tenant_id_index');
                $table->dropColumn('tenant_id');
            });
        }
    }
};
