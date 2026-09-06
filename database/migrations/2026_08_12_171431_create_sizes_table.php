// database/migrations/2026_08_12_xxxxxx_create_sizes_table.php

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sizes', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // S, M, L, XL, 40, 41, 42
            $table->string('description')->nullable(); // Small, Medium, Large
            $table->string('code')->nullable(); // SM, MD, LG
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sizes');
    }
};