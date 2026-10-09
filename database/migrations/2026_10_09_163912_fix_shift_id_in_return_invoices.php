<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ربط مرتجعات POS (ReturnInvoice) بالفواتير في نفس الوردية
        DB::statement("
            UPDATE return_invoices ri
            INNER JOIN invoices i ON i.id = ri.invoice_id
            INNER JOIN cashier_shifts cs 
                ON cs.opened_at <= ri.created_at 
                AND (cs.closed_at IS NULL OR cs.closed_at >= ri.created_at)
            SET ri.shift_id = cs.id
            WHERE ri.shift_id IS NULL
              AND cs.id = (
                  SELECT cs2.id FROM cashier_shifts cs2
                  WHERE cs2.opened_at <= ri.created_at
                    AND (cs2.closed_at IS NULL OR cs2.closed_at >= ri.created_at)
                  ORDER BY cs2.opened_at DESC
                  LIMIT 1
              )
        ");
    }

    public function down(): void
    {
        // لا شيء
    }
};