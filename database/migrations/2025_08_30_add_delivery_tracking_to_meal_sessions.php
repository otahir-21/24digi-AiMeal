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
            // Delivery scheduling and tracking
            $table->boolean('delivery_scheduled')->default(false)->after('approved_at');
            $table->timestamp('delivery_scheduled_at')->nullable()->after('delivery_scheduled');
            $table->json('nestjs_delivery_ids')->nullable()->after('delivery_scheduled_at');
            $table->enum('delivery_status', ['not_scheduled', 'scheduled', 'in_progress', 'completed', 'failed'])
                  ->default('not_scheduled')->after('nestjs_delivery_ids');
            
            // Consumption tracking for each day/meal
            $table->json('consumption_tracking')->nullable()->after('delivery_status');
            $table->json('delivery_preferences')->nullable()->after('consumption_tracking');
            
            // Statistics
            $table->integer('total_meals_delivered')->default(0)->after('delivery_preferences');
            $table->integer('total_meals_consumed')->default(0)->after('total_meals_delivered');
            $table->decimal('consumption_rate', 5, 2)->default(0)->after('total_meals_consumed');
            
            // Integration tracking
            $table->string('nestjs_profile_id')->nullable()->after('consumption_rate');
            $table->timestamp('last_delivery_sync')->nullable()->after('nestjs_profile_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meal_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'delivery_scheduled',
                'delivery_scheduled_at',
                'nestjs_delivery_ids',
                'delivery_status',
                'consumption_tracking',
                'delivery_preferences',
                'total_meals_delivered',
                'total_meals_consumed',
                'consumption_rate',
                'nestjs_profile_id',
                'last_delivery_sync'
            ]);
        });
    }
};