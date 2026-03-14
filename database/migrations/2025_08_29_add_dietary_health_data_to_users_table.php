<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Add dietary and health information columns
            $table->json('food_allergies')->nullable()->after('goal');
            $table->json('health_issues')->nullable()->after('food_allergies');
            $table->json('workout_habits')->nullable()->after('health_issues');
            $table->json('body_type')->nullable()->after('workout_habits');
            $table->json('food_ingredients')->nullable()->after('body_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'food_allergies',
                'health_issues',
                'workout_habits',
                'body_type',
                'food_ingredients'
            ]);
        });
    }
};