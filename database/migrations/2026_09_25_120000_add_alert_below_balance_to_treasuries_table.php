<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('treasuries') && !Schema::hasColumn('treasuries', 'alert_below_balance')) {
            Schema::table('treasuries', function (Blueprint $table): void {
                $table->decimal('alert_below_balance', 15, 2)->default(0)->after('balance');
            });
            DB::table('treasuries')->where('balance', '>', 0)->update([
                'alert_below_balance' => DB::raw('ROUND(balance * 0.10, 2)'),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('treasuries') && Schema::hasColumn('treasuries', 'alert_below_balance')) {
            Schema::table('treasuries', function (Blueprint $table): void {
                $table->dropColumn('alert_below_balance');
            });
        }
    }
};
