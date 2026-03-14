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
        Schema::table('meal_sessions', function (Blueprint $table) {
            // Subscription and planning fields
            $table->integer('subscription_months')->default(1)->after('total_days')
                ->comment('User subscription duration in months (1-3)');

            $table->integer('total_weeks_planned')->default(4)->after('subscription_months')
                ->comment('Total weeks to generate (subscription_months * 4)');

            $table->integer('current_week')->default(1)->after('current_day')
                ->comment('Current week user is viewing/using');

            $table->integer('weeks_generated')->default(0)->after('total_weeks_planned')
                ->comment('Number of weeks already generated');

            // Generation strategy
            $table->enum('generation_strategy', ['all_at_once', 'weekly'])
                ->default('weekly')->after('weeks_generated')
                ->comment('How meals are generated: all at once or week by week');

            // Next generation date for scheduler
            $table->timestamp('next_generation_at')->nullable()->after('completed_at')
                ->comment('When next week should be generated');

            // Add index for scheduler queries
            $table->index(['status', 'next_generation_at']);
            $table->index(['weeks_generated', 'total_weeks_planned']);
        });

        // Update status enum to include 'partially_completed'
        DB::statement("ALTER TABLE meal_sessions MODIFY COLUMN status ENUM('pending', 'processing', 'completed', 'partially_completed', 'failed') DEFAULT 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meal_sessions', function (Blueprint $table) {
            $table->dropIndex(['status', 'next_generation_at']);
            $table->dropIndex(['weeks_generated', 'total_weeks_planned']);

            $table->dropColumn([
                'subscription_months',
                'total_weeks_planned',
                'current_week',
                'weeks_generated',
                'generation_strategy',
                'next_generation_at'
            ]);
        });

        // Revert status enum
        DB::statement("ALTER TABLE meal_sessions MODIFY COLUMN status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending'");
    }
};
