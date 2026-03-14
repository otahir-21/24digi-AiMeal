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
            // Add NestJS profile ID for user-based meal tracking
            $table->string('nestjs_profile_id')->nullable()->after('user_hash');

            // Add index for efficient user lookups
            $table->index('nestjs_profile_id');
        });
    }

    
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['nestjs_profile_id']);
            $table->dropColumn('nestjs_profile_id');
        });
    }
};
