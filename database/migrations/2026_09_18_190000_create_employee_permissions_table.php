<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_permissions')) return;

        Schema::create('employee_permissions', function (Blueprint $table): void {
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['employee_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_permissions');
    }
};
