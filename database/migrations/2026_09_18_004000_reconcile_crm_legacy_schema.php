<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $softDeleteTables = ['pipeline_stages', 'leads', 'deals', 'crm_activities', 'email_templates', 'email_logs'];
        foreach ($softDeleteTables as $tableName) {
            if (Schema::hasTable($tableName) && !Schema::hasColumn($tableName, 'deleted_at')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->softDeletes());
            }
        }

        if (Schema::hasTable('pipeline_stages') && !Schema::hasColumn('pipeline_stages', 'position')) {
            Schema::table('pipeline_stages', fn (Blueprint $table) => $table->unsignedInteger('position')->default(0));
            if (Schema::hasColumn('pipeline_stages', 'sort_order')) {
                DB::statement('UPDATE pipeline_stages SET position = sort_order');
            }
        }

        if (Schema::hasTable('deals') && !Schema::hasColumn('deals', 'pipeline_stage_id')) {
            Schema::table('deals', function (Blueprint $table): void {
                $table->unsignedBigInteger('pipeline_stage_id')->nullable()->index();
            });
            if (Schema::hasColumn('deals', 'stage_id')) {
                DB::statement('UPDATE deals SET pipeline_stage_id = stage_id WHERE pipeline_stage_id IS NULL');
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('deals') && Schema::hasColumn('deals', 'pipeline_stage_id')) {
            Schema::table('deals', fn (Blueprint $table) => $table->dropColumn('pipeline_stage_id'));
        }
        if (Schema::hasTable('pipeline_stages') && Schema::hasColumn('pipeline_stages', 'position')) {
            Schema::table('pipeline_stages', fn (Blueprint $table) => $table->dropColumn('position'));
        }
    }
};
