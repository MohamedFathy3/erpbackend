<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'journal_entries' => 'posted_by',
            'accounting_documents' => 'created_by',
        ] as $table => $prefix) {
            if (!Schema::hasTable($table)) continue;
            $typeColumn = $prefix . '_type';
            $idColumn = $prefix . '_id';
            Schema::table($table, function (Blueprint $blueprint) use ($table, $prefix, $typeColumn, $idColumn): void {
                if (!Schema::hasColumn($table, $typeColumn)) $blueprint->string($typeColumn)->nullable();
                if (!Schema::hasColumn($table, $idColumn)) $blueprint->unsignedBigInteger($idColumn)->nullable();
                $index = $table . '_' . $prefix . '_morph_idx';
                $blueprint->index([$typeColumn, $idColumn], $index);
            });
        }
    }

    public function down(): void
    {
        // Actor identifiers are retained so audit history is not destroyed by rollback.
    }
};
