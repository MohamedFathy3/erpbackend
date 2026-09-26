<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { if (Schema::hasTable('invoice_transfer_requests') && !Schema::hasColumn('invoice_transfer_requests', 'deleted_at')) Schema::table('invoice_transfer_requests', fn (Blueprint $table) => $table->softDeletes()); }
    public function down(): void { if (Schema::hasTable('invoice_transfer_requests') && Schema::hasColumn('invoice_transfer_requests', 'deleted_at')) Schema::table('invoice_transfer_requests', fn (Blueprint $table) => $table->dropSoftDeletes()); }
};
