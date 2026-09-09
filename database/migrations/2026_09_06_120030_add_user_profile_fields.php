<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $fields = [
                'address_line_one' => fn () => $table->string('address_line_one')->nullable(),
                'address_line_two' => fn () => $table->string('address_line_two')->nullable(),
                'city' => fn () => $table->string('city')->nullable(),
                'state' => fn () => $table->string('state')->nullable(),
                'postal_code' => fn () => $table->string('postal_code')->nullable(),
                'website' => fn () => $table->string('website')->nullable(),
                'members_count' => fn () => $table->unsignedInteger('members_count')->default(0),
                'country_id' => fn () => $table->unsignedBigInteger('country_id')->nullable(),
                'business_est' => fn () => $table->unsignedSmallInteger('business_est')->nullable(),
                'profile' => fn () => $table->text('profile')->nullable(),
                'fpp' => fn () => $table->string('fpp')->nullable(),
                'unhashed_password' => fn () => $table->string('unhashed_password')->nullable(),
            ];

            foreach ($fields as $column => $definition) {
                if (!Schema::hasColumn('users', $column)) {
                    $definition();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach ([
                'address_line_one', 'address_line_two', 'city', 'state', 'postal_code',
                'website', 'members_count', 'country_id', 'business_est', 'profile', 'fpp',
                'unhashed_password',
            ] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
