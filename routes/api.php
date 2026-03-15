<?php

use App\Http\Controllers\WebController;
use App\Http\Controllers\Api\MobileApiController;
use App\Http\Controllers\Api\StreamController;
use App\Http\Controllers\Api\SessionDebugController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Existing endpoint
Route::post('/v1/generate-ai-meal', [WebController::class, 'generateUserAiMeal'])->name('generate-user-ai-meal');

// Mobile API Endpoints (No Authentication Required)
Route::prefix('v1/mobile')->group(function () {
    // Main endpoint to generate meals (can be long-running; use /generate-meals/start + /status for polling)
    Route::post('/generate-meals', [MobileApiController::class, 'generateMeals'])->name('api.mobile.generate');

    // Polling flow: start (returns session_id in <2s), then poll status for JSON meal_data
    Route::post('/generate-meals/start', [MobileApiController::class, 'startGenerationSession'])->name('api.mobile.generate.start');
    Route::post('/generate-meals/status', [MobileApiController::class, 'getGenerationStatus'])->name('api.mobile.generate.status');

    // SSE streaming endpoint
    Route::get('/stream/{sessionId}', [StreamController::class, 'stream'])->name('api.mobile.stream');
    
    // Get session status
    Route::get('/session/{sessionId}/status', [MobileApiController::class, 'getSessionStatus'])->name('api.mobile.session.status');
    
    // Check if user has existing meals
    Route::get('/check-meals/{deviceId}', [MobileApiController::class, 'checkUserMeals'])->name('api.mobile.check-meals');
    Route::get('/check-meals-by-user/{userId}', [MobileApiController::class, 'checkUserMealsByUserId'])->name('api.mobile.check-meals-by-user');
    
    // Approve generated meal plan
    Route::post('/meals/{sessionId}/approve', [MobileApiController::class, 'approveMeals'])->name('api.mobile.approve-meals');
    
    // AI Meal Delivery Tracking Endpoints
    Route::post('/meals/{sessionId}/schedule-delivery', [MobileApiController::class, 'scheduleDelivery'])->name('api.mobile.schedule-delivery');
    Route::post('/meals/{sessionId}/consumption', [MobileApiController::class, 'updateConsumption'])->name('api.mobile.update-consumption');
    Route::get('/meals/{sessionId}/delivery-status', [MobileApiController::class, 'getDeliveryStatus'])->name('api.mobile.delivery-status');
    
    // NestJS Integration Webhook
    Route::post('/webhook/delivery-status', [MobileApiController::class, 'deliveryStatusWebhook'])->name('api.mobile.delivery-webhook');
    
    // Get existing meal plan
    Route::get('/meal-plan/{userIdentifier}', [MobileApiController::class, 'getMealPlan'])->name('api.mobile.meal-plan');

    // Download meal plan as PDF
    Route::get('/meal-plan/{userIdentifier}/pdf', [MobileApiController::class, 'downloadPdf'])->name('api.mobile.meal-plan.pdf');

    // Get meals by session ID (for NestJS sync)
    Route::get('/meals/{sessionId}', [MobileApiController::class, 'getMealsBySession'])->name('api.mobile.meals-by-session');
});

// Debug endpoints for session management
Route::prefix('v1/debug')->group(function () {
    // Get all sessions
    Route::get('/sessions', [SessionDebugController::class, 'getAllSessions']);
    
    // Get stuck sessions
    Route::get('/sessions/stuck', [SessionDebugController::class, 'getStuckSessions']);
    
    // Cleanup stuck sessions
    Route::post('/sessions/cleanup', [SessionDebugController::class, 'cleanupStuckSessions']);
    
    // Cancel specific session
    Route::post('/sessions/{sessionId}/cancel', [SessionDebugController::class, 'cancelSession']);
    
    // Get user's session history
    Route::get('/users/{userId}/sessions', [SessionDebugController::class, 'getUserSessions']);
});

