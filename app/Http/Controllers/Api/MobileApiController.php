<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\MealSession;
use App\Services\UserIdentificationService;
use App\Services\MealGenerationService;
use App\Services\NestJSIntegrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Jobs\GenerateMealPlanJob;

class MobileApiController extends Controller
{
    protected $userService;
    protected $mealService;
    protected $nestjsService;

    public function __construct(
        UserIdentificationService $userService,
        MealGenerationService $mealService,
        NestJSIntegrationService $nestjsService
    ) {
        $this->userService = $userService;
        $this->mealService = $mealService;
        $this->nestjsService = $nestjsService;
    }

    /**
     * Generate meals for mobile user
     * No authentication required - users identified by physical metrics
     */
    public function generateMeals(Request $request)
    {
        // Set max execution time for long-running meal generation
        ini_set('max_execution_time', 1800); // 30 minutes
        
        Log::info('[API] Meal generation request received', [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'timestamp' => now()->toDateTimeString()
        ]);
        
        try {
            // Validate request
            $validated = $request->validate([
                'device_id' => 'nullable|string|max:255',
                'user_id' => 'nullable|string|max:255', // NestJS profile ID
                'age' => 'required|integer|min:1|max:120',
                'height' => 'required|numeric|min:50|max:300', // in cm
                'weight' => 'required|numeric|min:20|max:500', // in kg
                'gender' => 'required|in:male,female',
                'activity_level' => 'required|string',
                'neck_circumference' => 'required|numeric|min:10|max:100',
                'waist_circumference' => 'required|numeric|min:30|max:200',
                'hip_circumference' => 'nullable|numeric|min:30|max:200',
                'plan_period' => 'nullable|integer|in:7,14,21,28',
                // Add dietary and health information validation
                'food_allergies' => 'nullable|array',
                'food_allergies.redMeatAllergy' => 'nullable|boolean',
                'food_allergies.milkProductAllergy' => 'nullable|boolean',
                'food_allergies.customAllergies' => 'nullable|array',
                'food_allergies.customAllergies.*' => 'nullable|string|max:255',
                'health_issues' => 'nullable|array',
                'health_issues.*' => 'nullable|string|max:255',
                'workout_habits' => 'nullable|array',
                'workout_habits.*' => 'nullable|string|max:255',
                'body_type' => 'nullable|array',
                'body_type.*' => 'nullable|string|max:255',
                'food_ingredients' => 'nullable|array',
            ]);

            // Set default plan period to 7 days (1 week)
            $validated['plan_period'] = $validated['plan_period'] ?? 7;
            
            Log::info('[API] Validated request data', [
                'device_id' => $validated['device_id'] ?? 'not provided',
                'age' => $validated['age'],
                'height' => $validated['height'],
                'weight' => $validated['weight'],
                'gender' => $validated['gender'],
                'plan_period' => $validated['plan_period'],
                'activity_level' => $validated['activity_level'],
                'has_food_allergies' => !empty($validated['food_allergies']),
                'health_issues_count' => count($validated['health_issues'] ?? []),
                'workout_habits_count' => count($validated['workout_habits'] ?? []),
                'body_type_count' => count($validated['body_type'] ?? []),
                'has_food_preferences' => !empty($validated['food_ingredients'])
            ]);

            DB::beginTransaction();

            // Find or create user based on physical metrics
            $user = $this->userService->findOrCreateUser($validated);
            
            Log::info('[API] User identified', [
                'user_id' => $user->id,
                'is_new_user' => $user->wasRecentlyCreated,
                'user_hash' => $user->user_hash
            ]);
            
            // Check for existing active sessions
            $activeSession = $user->mealSessions()
                ->whereIn('status', ['pending', 'processing'])
                ->first();
                
            if ($activeSession) {
                // Check if session is stuck
                $minutesSinceUpdate = $activeSession->updated_at->diffInMinutes(now());
                
                Log::warning('[API] User has an existing active session', [
                    'user_id' => $user->id,
                    'session_id' => $activeSession->id,
                    'status' => $activeSession->status,
                    'current_day' => $activeSession->current_day,
                    'minutes_since_update' => $minutesSinceUpdate
                ]);
                
                // If session is stuck (no updates for 10+ minutes), mark it as failed
                if ($minutesSinceUpdate > 10) {
                    Log::info('[API] Marking stuck session as failed', [
                        'session_id' => $activeSession->id,
                        'stuck_at_day' => $activeSession->current_day
                    ]);
                    
                    $activeSession->update([
                        'status' => 'failed',
                        'error_message' => 'Session timed out - replaced by new request'
                    ]);
                } else {
                    // Return existing active session
                    $streamUrl = route('api.mobile.stream', ['sessionId' => $activeSession->id]);
                    $streamUrl = str_replace('http://', 'https://', $streamUrl);
                    
                    Log::info('[API] Returning existing active session', [
                        'session_id' => $activeSession->id
                    ]);
                    
                    return response()->json([
                        'success' => true,
                        'data' => [
                            'session_id' => $activeSession->id,
                            'stream_url' => $streamUrl,
                            'user_id' => $user->id,
                            'user_hash' => $user->user_hash,
                            'is_existing_session' => true,
                            'session_status' => $activeSession->status,
                            'current_progress' => [
                                'current_day' => $activeSession->current_day,
                                'total_days' => $activeSession->total_days
                            ]
                        ],
                        'message' => 'Existing meal generation session found'
                    ]);
                }
            }

            // Create meal generation session
            $session = $this->mealService->createSession($user, $validated['plan_period']);
            
            Log::info('[API] Meal generation session created', [
                'session_id' => $session->id,
                'user_id' => $user->id,
                'plan_period' => $validated['plan_period'],
                'goal' => $session->goal,
                'status' => $session->status
            ]);

            // Start async meal generation (will be processed in background)
            $this->mealService->startGeneration($session);
            
            Log::info('[API] Meal generation started', [
                'session_id' => $session->id,
                'processing_method' => config('queue.default') === 'sync' ? 'synchronous' : 'queued'
            ]);

            DB::commit();

            // Return session info for streaming
            // Force HTTPS for stream URL to match the main API
            $streamUrl = route('api.mobile.stream', ['sessionId' => $session->id]);
            $streamUrl = str_replace('http://', 'https://', $streamUrl);
            
            Log::info('[API] Generation started successfully', [
                'session_id' => $session->id,
                'stream_url' => $streamUrl,
                'response_sent_at' => now()->toDateTimeString()
            ]);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'session_id' => $session->id,
                    'stream_url' => $streamUrl,
                    'user_id' => $user->id,
                    'user_hash' => $user->user_hash,
                    'is_existing_user' => $user->wasRecentlyCreated ? false : true,
                    'user_metrics' => [
                        'bmi' => $user->bmi,
                        'bmi_overview' => $user->bmi_overview,
                        'bmr' => $user->bmr,
                        'tdee' => $user->tdee,
                        'body_fat' => $user->body_fat,
                        'goal' => $user->goal
                    ]
                ],
                'message' => 'Meal generation started successfully'
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Mobile API Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to start meal generation',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Start meal generation (async). Returns session_id in <2s for Flutter polling.
     * POST /api/v1/mobile/generate-meals/start
     */
    public function startGenerationSession(Request $request)
    {
        try {
            $validated = $request->validate([
                'device_id' => 'nullable|string|max:255',
                'user_id' => 'nullable|string|max:255',
                'age' => 'required|integer|min:1|max:120',
                'height' => 'required|numeric|min:50|max:300',
                'weight' => 'required|numeric|min:20|max:500',
                'gender' => 'required|in:male,female',
                'activity_level' => 'required|string',
                'neck_circumference' => 'required|numeric|min:10|max:100',
                'waist_circumference' => 'required|numeric|min:30|max:200',
                'hip_circumference' => 'nullable|numeric|min:30|max:200',
                'plan_period' => 'nullable|integer|in:7,14,21,28',
                'food_allergies' => 'nullable|array',
                'food_allergies.redMeatAllergy' => 'nullable|boolean',
                'food_allergies.milkProductAllergy' => 'nullable|boolean',
                'food_allergies.customAllergies' => 'nullable|array',
                'food_allergies.customAllergies.*' => 'nullable|string|max:255',
                'health_issues' => 'nullable|array',
                'health_issues.*' => 'nullable|string|max:255',
                'workout_habits' => 'nullable|array',
                'workout_habits.*' => 'nullable|string|max:255',
                'body_type' => 'nullable|array',
                'body_type.*' => 'nullable|string|max:255',
                'food_ingredients' => 'nullable|array',
            ]);

            $validated['plan_period'] = $validated['plan_period'] ?? 7;

            DB::beginTransaction();

            $user = $this->userService->findOrCreateUser($validated);

            $activeSession = $user->mealSessions()
                ->whereIn('status', ['pending', 'processing'])
                ->first();

            if ($activeSession) {
                $minutesSinceUpdate = $activeSession->updated_at->diffInMinutes(now());
                if ($minutesSinceUpdate > 10) {
                    $activeSession->update([
                        'status' => 'failed',
                        'error_message' => 'Session timed out - replaced by new request'
                    ]);
                } else {
                    DB::commit();
                    return response()->json([
                        'success' => true,
                        'session_id' => (string) $activeSession->id,
                        'message' => 'Generation started in the background.'
                    ]);
                }
            }

            $session = $this->mealService->createSession($user, $validated['plan_period']);
            $session->update(['status' => 'processing']);

            GenerateMealPlanJob::dispatch($session);

            DB::commit();

            return response()->json([
                'success' => true,
                'session_id' => (string) $session->id,
                'message' => 'Generation started in the background.'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[API] startGenerationSession error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to start meal generation',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Poll generation status. Returns pure JSON meal_data for Flutter (no HTML).
     * POST /api/v1/mobile/generate-meals/status
     */
    public function getGenerationStatus(Request $request)
    {
        try {
            $validated = $request->validate([
                'session_id' => 'required|string',
                'current_day' => 'nullable|integer|min:0',
            ]);

            $session = MealSession::find($validated['session_id']);

            if (!$session) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session not found'
                ], 404);
            }

            $totalDays = (int) $session->total_days;
            $currentDay = (int) $session->current_day;
            $progress = $totalDays > 0 ? round(($currentDay / $totalDays) * 100, 1) : 0;
            $completed = $session->status === 'completed';

            $mealDataFormatted = [];
            if ($session->meal_data && $session->daily_totals) {
                $allMeals = json_decode($session->meal_data, true);
                $dailyTotals = json_decode($session->daily_totals, true);
                if (is_array($allMeals) && is_array($dailyTotals)) {
                    foreach ($allMeals as $dayIndex => $dayMeals) {
                        $dayNum = (string) ($dayIndex + 1);
                        $totals = $dailyTotals[$dayIndex] ?? ['calories' => 0, 'protein' => 0, 'carbs' => 0, 'fat' => 0, 'price' => 0];
                        $mealsForDay = is_array($dayMeals) ? $dayMeals : [];
                        $mealDataFormatted[$dayNum] = [
                            'daily_total_cal' => (int) ($totals['calories'] ?? 0),
                            'daily_total_cost' => (float) ($totals['price'] ?? 0),
                            'meals' => $this->formatMealsForMobile($mealsForDay),
                        ];
                    }
                }
            }

            return response()->json([
                'success' => true,
                'completed' => $completed,
                'day_completed' => $currentDay,
                'total_days' => $totalDays,
                'progress' => $progress,
                'status' => $session->status,
                'meal_data' => $mealDataFormatted,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('[API] getGenerationStatus error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get status',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Format stored meals to Flutter-friendly JSON (type, name, time, total_cal, total_price, ingredients).
     */
    private function formatMealsForMobile(array $meals): array
    {
        $out = [];
        foreach ($meals as $meal) {
            $ingredients = [];
            foreach ($meal['ingredients'] ?? [] as $ing) {
                $ingredients[] = [
                    'name' => $ing['name'] ?? '',
                    'amount' => $ing['amount'] ?? '',
                    'price' => (float) ($ing['price'] ?? 0),
                    'cal' => (int) ($ing['cal'] ?? 0),
                    'protein' => (float) ($ing['protein'] ?? 0),
                    'carbs' => (float) ($ing['carbs'] ?? 0),
                    'fat' => (float) ($ing['fat'] ?? $ing['fats_per_100g'] ?? 0),
                ];
            }
            foreach ($meal['sauces'] ?? [] as $s) {
                $ingredients[] = [
                    'name' => $s['name'] ?? '',
                    'amount' => $s['amount'] ?? '',
                    'price' => (float) ($s['price'] ?? 0),
                    'cal' => (int) ($s['cal'] ?? 0),
                    'protein' => (float) ($s['protein'] ?? 0),
                    'carbs' => (float) ($s['carbs'] ?? 0),
                    'fat' => (float) ($s['fat'] ?? $s['fats_per_100g'] ?? 0),
                ];
            }
            $out[] = [
                'type' => $meal['type'] ?? '',
                'name' => $meal['name'] ?? '',
                'time' => $meal['time'] ?? '',
                'total_cal' => (int) ($meal['total_cal'] ?? 0),
                'total_price' => (float) ($meal['total_price'] ?? 0),
                'ingredients' => $ingredients,
            ];
        }
        return $out;
    }

    /**
     * Get existing meal plan for user
     */
    public function getMealPlan(Request $request, $userIdentifier)
    {
        try {
            // Find user by hash or device_id
            $user = User::where('user_hash', $userIdentifier)
                ->orWhere('device_id', $userIdentifier)
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Get latest completed session
            $session = $user->mealSessions()
                ->where('status', 'completed')
                ->latest()
                ->first();

            if (!$session) {
                return response()->json([
                    'success' => false,
                    'message' => 'No completed meal plan found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'session_id' => $session->id,
                    'goal' => $session->goal,
                    'goal_explanation' => $session->goal_explanation,
                    'total_days' => $session->total_days,
                    'meal_plan' => $session->meal_data,
                    'daily_totals' => $session->daily_totals,
                    'summary' => [
                        'total_calories' => $session->total_calories,
                        'total_protein' => $session->total_protein,
                        'total_carbs' => $session->total_carbs,
                        'total_fat' => $session->total_fat,
                        'total_price' => $session->total_price,
                        'total_meals' => $session->total_meals
                    ],
                    'generated_at' => $session->completed_at
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Get Meal Plan Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve meal plan',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get meals by session ID (for NestJS sync)
     */
    public function getMealsBySession($sessionId)
    {
        try {
            Log::info('[API] Fetching meals by session ID for NestJS sync', ['session_id' => $sessionId]);

            $session = MealSession::find($sessionId);

            if (!$session) {
                Log::warning('[API] Session not found for sync', ['session_id' => $sessionId]);
                return response()->json([
                    'success' => false,
                    'message' => 'Session not found'
                ], 404);
            }

            if ($session->status !== 'completed') {
                Log::warning('[API] Session not completed for sync', [
                    'session_id' => $sessionId,
                    'status' => $session->status
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Session is not completed yet',
                    'current_status' => $session->status
                ], 400);
            }

            // Parse meal data
            $mealData = json_decode($session->meal_data, true);
            $dailyTotals = json_decode($session->daily_totals, true);

            if (!$mealData) {
                Log::error('[API] No meal data found in session', ['session_id' => $sessionId]);
                return response()->json([
                    'success' => false,
                    'message' => 'No meal data found in session'
                ], 404);
            }

            // Transform meal data to flat array format expected by NestJS
            $meals = [];
            foreach ($mealData as $dayIndex => $dayMeals) {
                $day = $dayIndex + 1;

                // Handle both array and JSON string formats
                if (is_string($dayMeals)) {
                    $dayMeals = json_decode($dayMeals, true);
                }

                if (is_array($dayMeals)) {
                    foreach ($dayMeals as $meal) {
                        // Add day information to each meal
                        $meal['day'] = $day;
                        $meal['date'] = now()->addDays($dayIndex)->format('Y-m-d');
                        $meals[] = $meal;
                    }
                }
            }

            Log::info('[API] Returning meals for NestJS sync', [
                'session_id' => $sessionId,
                'total_days' => count($mealData),
                'total_meals' => count($meals)
            ]);

            return response()->json([
                'success' => true,
                'data' => $meals
            ]);

        } catch (\Exception $e) {
            Log::error('[API] Get Meals By Session Error', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve meals',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get session status
     */
    public function getSessionStatus($sessionId)
    {
        try {
            $session = DB::table('meal_sessions')->find($sessionId);

            if (!$session) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session not found'
                ], 404);
            }

            // Parse meal data if available
            $mealData = null;
            if ($session->meal_data && $session->current_day > 0) {
                $allMeals = json_decode($session->meal_data, true);
                $dailyTotals = $session->daily_totals ? json_decode($session->daily_totals, true) : [];
                
                // Get the last completed day's data
                $dayIndex = $session->current_day - 1;
                if (isset($allMeals[$dayIndex])) {
                    // The data might be stored as a JSON string, need to decode it
                    $dayMeals = $allMeals[$dayIndex];
                    
                    // If the day meals is a string, it's JSON encoded and needs to be decoded
                    if (is_string($dayMeals)) {
                        $dayMeals = json_decode($dayMeals, true);
                    }
                    
                    // Log the structure for debugging
                    \Log::info('[API] Returning meal data for polling request', [
                        'session_id' => $sessionId,
                        'day' => $session->current_day,
                        'dayIndex' => $dayIndex,
                        'dayMealsType' => gettype($dayMeals),
                        'isArray' => is_array($dayMeals),
                        'dayMealsCount' => is_array($dayMeals) ? count($dayMeals) : 'not an array',
                        'has_daily_total' => isset($dailyTotals[$dayIndex])
                    ]);
                    
                    // Ensure meals is an array
                    if (!is_array($dayMeals)) {
                        $dayMeals = [];
                    }
                    
                    $mealData = [
                        'day' => $session->current_day,
                        'goal' => $session->goal,
                        'meals' => $dayMeals, // This is already the meals array
                        'daily_total' => isset($dailyTotals[$dayIndex]) ? $dailyTotals[$dayIndex] : [
                            'calories' => 0,
                            'protein' => 0,
                            'carbs' => 0,
                            'fat' => 0,
                            'price' => 0
                        ]
                    ];
                }
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'session_id' => $session->id,
                    'status' => $session->status,
                    'current_day' => $session->current_day,
                    'total_days' => $session->total_days,
                    'progress' => $session->total_days > 0 
                        ? round(($session->current_day / $session->total_days) * 100, 2) 
                        : 0,
                    'meal_data' => $mealData, // Include meal data for the current day
                    'error_message' => $session->error_message,
                    'started_at' => $session->started_at,
                    'completed_at' => $session->completed_at
                ]
            ]);

            // Log polling request
            \Log::info('[API] Session status polled', [
                'session_id' => $sessionId,
                'status' => $session->status,
                'current_day' => $session->current_day,
                'total_days' => $session->total_days,
                'has_meal_data' => $mealData !== null
            ]);
            
        } catch (\Exception $e) {
            Log::error('[API] Get Session Status Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve session status',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Check if user has existing meals by NestJS user ID (preferred method)
     */
    public function checkUserMealsByUserId($userId)
    {
        try {
            Log::info('[API] Checking user meals by user ID', ['user_id' => $userId]);
            
            // Find user by nestjs_profile_id first, fallback to device lookup
            $user = User::where('nestjs_profile_id', $userId)->first();
            
            // If not found by profile ID, try to find by device and link the profile
            if (!$user) {
                Log::info('[API] User not found by profile ID, this indicates a new user', [
                    'user_id' => $userId
                ]);
                
                return response()->json([
                    'success' => true,
                    'data' => [
                        'has_meals' => false,
                        'message' => 'No AI meal sessions found for this user',
                        'user_linked' => false
                    ]
                ]);
            }

            Log::info('[API] User found for user ID check', [
                'laravel_user_id' => $user->id,
                'nestjs_profile_id' => $user->nestjs_profile_id,
                'user_hash' => $user->user_hash,
                'device_id' => $user->device_id,
                'last_generation' => $user->last_generation_at
            ]);

            // Check for active sessions (pending or processing)
            $activeSession = $user->mealSessions()
                ->whereIn('status', ['pending', 'processing'])
                ->latest()
                ->first();
                
            if ($activeSession) {
                Log::info('[API] Found active session for user', [
                    'user_id' => $userId,
                    'session_id' => $activeSession->id,
                    'status' => $activeSession->status
                ]);
                
                return response()->json([
                    'success' => true,
                    'data' => [
                        'has_meals' => false,
                        'is_generating' => true,
                        'session' => [
                            'id' => $activeSession->id,
                            'status' => $activeSession->status,
                            'current_day' => $activeSession->current_day,
                            'total_days' => $activeSession->total_days,
                            'progress' => $activeSession->total_days > 0 
                                ? round(($activeSession->current_day / $activeSession->total_days) * 100, 2) 
                                : 0
                        ]
                    ]
                ]);
            }
            
            // Get latest completed session (only recent ones)
            $completedSession = $user->mealSessions()
                ->where('status', 'completed')
                ->where('completed_at', '>=', now()->subDays(7))
                ->latest()
                ->first();

            Log::info('[API] Checking completed sessions for user', [
                'user_id' => $userId,
                'completed_session_found' => !!$completedSession,
                'session_id' => $completedSession?->id ?? null
            ]);
                
            if ($completedSession) {
                $isApproved = $completedSession->is_approved ?? false;
                
                return response()->json([
                    'success' => true,
                    'data' => [
                        'has_meals' => true,
                        'is_approved' => $isApproved,
                        'session' => [
                            'id' => $completedSession->id,
                            'goal' => $completedSession->goal,
                            'goal_explanation' => $completedSession->goal_explanation,
                            'total_days' => $completedSession->total_days,
                            'meal_plan' => json_decode($completedSession->meal_data, true),
                            'daily_totals' => json_decode($completedSession->daily_totals, true),
                            'summary' => [
                                'total_calories' => $completedSession->total_calories,
                                'total_protein' => $completedSession->total_protein,
                                'total_carbs' => $completedSession->total_carbs,
                                'total_fat' => $completedSession->total_fat,
                                'total_price' => $completedSession->total_price,
                                'total_meals' => $completedSession->total_meals
                            ],
                            'generated_at' => $completedSession->completed_at,
                            'approved_at' => $completedSession->approved_at
                        ],
                        'user' => [
                            'id' => $user->id,
                            'user_hash' => $user->user_hash,
                            'nestjs_profile_id' => $user->nestjs_profile_id,
                            'metrics' => [
                                'bmi' => $user->bmi,
                                'bmi_overview' => $user->bmi_overview,
                                'bmr' => $user->bmr,
                                'tdee' => $user->tdee,
                                'body_fat' => $user->body_fat
                            ]
                        ]
                    ]
                ]);
            }
            
            // No meals found for this user
            return response()->json([
                'success' => true,
                'data' => [
                    'has_meals' => false,
                    'message' => 'No meal plans found for this user'
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('[API] Check User Meals By User ID Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to check user meals',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Check if user has existing meals (legacy device-based method)
     */
    public function checkUserMeals($deviceId)
    {
        try {
            Log::info('[API] Checking user meals', ['device_id' => $deviceId]);
            
            // Find user by device_id
            $user = User::where('device_id', $deviceId)->first();
            
            if (!$user) {
                Log::info('[API] No user found for device, returning clean state', ['device_id' => $deviceId]);
                return response()->json([
                    'success' => true,
                    'data' => [
                        'has_meals' => false,
                        'message' => 'No user found with this device'
                    ]
                ]);
            }

            Log::info('[API] User found for device check', [
                'user_id' => $user->id,
                'user_hash' => $user->user_hash,
                'created_at' => $user->created_at,
                'last_generation' => $user->last_generation_at
            ]);
            
            // Check for active sessions (pending or processing)
            $activeSession = $user->mealSessions()
                ->whereIn('status', ['pending', 'processing'])
                ->latest()
                ->first();
                
            if ($activeSession) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'has_meals' => false,
                        'is_generating' => true,
                        'session' => [
                            'id' => $activeSession->id,
                            'status' => $activeSession->status,
                            'current_day' => $activeSession->current_day,
                            'total_days' => $activeSession->total_days,
                            'progress' => $activeSession->total_days > 0 
                                ? round(($activeSession->current_day / $activeSession->total_days) * 100, 2) 
                                : 0
                        ]
                    ]
                ]);
            }
            
            // Get latest completed session (only recent ones to avoid stale data)
            $completedSession = $user->mealSessions()
                ->where('status', 'completed')
                ->where('completed_at', '>=', now()->subDays(7)) // Only sessions from last 7 days
                ->latest()
                ->first();

            Log::info('[API] Checking completed sessions', [
                'user_id' => $user->id,
                'completed_session_found' => !!$completedSession,
                'session_id' => $completedSession?->id,
                'completed_at' => $completedSession?->completed_at,
                'is_approved' => $completedSession?->is_approved ?? false
            ]);
                
            if ($completedSession) {
                // Check if meals are approved
                $isApproved = $completedSession->is_approved ?? false;
                
                return response()->json([
                    'success' => true,
                    'data' => [
                        'has_meals' => true,
                        'is_approved' => $isApproved,
                        'session' => [
                            'id' => $completedSession->id,
                            'goal' => $completedSession->goal,
                            'goal_explanation' => $completedSession->goal_explanation,
                            'total_days' => $completedSession->total_days,
                            'meal_plan' => json_decode($completedSession->meal_data, true),
                            'daily_totals' => json_decode($completedSession->daily_totals, true),
                            'summary' => [
                                'total_calories' => $completedSession->total_calories,
                                'total_protein' => $completedSession->total_protein,
                                'total_carbs' => $completedSession->total_carbs,
                                'total_fat' => $completedSession->total_fat,
                                'total_price' => $completedSession->total_price,
                                'total_meals' => $completedSession->total_meals
                            ],
                            'generated_at' => $completedSession->completed_at,
                            'approved_at' => $completedSession->approved_at
                        ],
                        'user' => [
                            'id' => $user->id,
                            'user_hash' => $user->user_hash,
                            'metrics' => [
                                'bmi' => $user->bmi,
                                'bmi_overview' => $user->bmi_overview,
                                'bmr' => $user->bmr,
                                'tdee' => $user->tdee,
                                'body_fat' => $user->body_fat
                            ]
                        ]
                    ]
                ]);
            }
            
            // No meals found
            return response()->json([
                'success' => true,
                'data' => [
                    'has_meals' => false,
                    'message' => 'No meal plans found for this user'
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('[API] Check User Meals Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to check user meals',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Approve generated meal plan
     */
    public function approveMeals(Request $request, $sessionId)
    {
        try {
            Log::info('[API] 🎯 Starting meal plan approval', [
                'session_id' => $sessionId,
                'request_ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toDateTimeString()
            ]);
            
            $session = DB::table('meal_sessions')->where('id', $sessionId)->first();
            
            if (!$session) {
                Log::warning('[API] ❌ Approval failed - Session not found', [
                    'session_id' => $sessionId,
                    'search_attempted' => true
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Session not found'
                ], 404);
            }

            Log::info('[API] 📊 Session found for approval', [
                'session_id' => $sessionId,
                'status' => $session->status,
                'current_day' => $session->current_day,
                'total_days' => $session->total_days,
                'user_id' => $session->user_id,
                'has_meal_data' => !empty($session->meal_data),
                'already_approved' => $session->is_approved ?? false,
                'created_at' => $session->created_at,
                'completed_at' => $session->completed_at
            ]);
            
            if ($session->status !== 'completed') {
                Log::warning('[API] ❌ Approval failed - Session not completed', [
                    'session_id' => $sessionId,
                    'current_status' => $session->status,
                    'current_day' => $session->current_day,
                    'total_days' => $session->total_days,
                    'error_message' => $session->error_message
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Can only approve completed meal plans',
                    'details' => [
                        'current_status' => $session->status,
                        'progress' => "{$session->current_day}/{$session->total_days} days"
                    ]
                ], 400);
            }

            if ($session->is_approved) {
                Log::info('[API] ⚠️ Session already approved', [
                    'session_id' => $sessionId,
                    'approved_at' => $session->approved_at
                ]);
                return response()->json([
                    'success' => true,
                    'message' => 'Meal plan is already approved',
                    'data' => [
                        'session_id' => $sessionId,
                        'approved_at' => $session->approved_at,
                        'already_approved' => true
                    ]
                ]);
            }
            
            // Update session as approved
            $updateResult = DB::table('meal_sessions')
                ->where('id', $sessionId)
                ->update([
                    'is_approved' => true,
                    'approved_at' => now(),
                    'updated_at' => now()
                ]);

            if ($updateResult) {
                Log::info('[API] ✅ Meal plan approved successfully', [
                    'session_id' => $sessionId,
                    'approved_at' => now()->toDateTimeString(),
                    'rows_updated' => $updateResult
                ]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Meal plan approved successfully',
                    'data' => [
                        'session_id' => $sessionId,
                        'approved_at' => now()->toIso8601String(),
                        'status' => 'approved'
                    ]
                ]);
            } else {
                Log::error('[API] ❌ Database update failed during approval', [
                    'session_id' => $sessionId,
                    'update_result' => $updateResult
                ]);
                throw new \Exception('Failed to update session approval status');
            }
            
        } catch (\Exception $e) {
            Log::error('[API] ❌ Approve Meals Error', [
                'session_id' => $sessionId,
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve meal plan',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
                'debug_info' => config('app.debug') ? [
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ] : null
            ], 500);
        }
    }

    /**
     * Schedule AI meal deliveries
     */
    public function scheduleDelivery(Request $request, $sessionId)
    {
        try {
            Log::info('[API] 📅 Scheduling AI meal deliveries', ['session_id' => $sessionId]);
            
            $session = MealSession::find($sessionId);
            
            if (!$session) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session not found'
                ], 404);
            }
            
            if (!$session->is_approved) {
                return response()->json([
                    'success' => false,
                    'message' => 'Can only schedule delivery for approved meal plans'
                ], 400);
            }

            if ($session->delivery_scheduled) {
                return response()->json([
                    'success' => false,
                    'message' => 'Delivery already scheduled for this session',
                    'data' => [
                        'scheduled_at' => $session->delivery_scheduled_at,
                        'delivery_status' => $session->delivery_status
                    ]
                ], 400);
            }
            
            // Validate delivery preferences
            $deliveryPreferences = $request->validate([
                'delivery_times' => 'nullable|array',
                'delivery_times.breakfast' => 'nullable|string',
                'delivery_times.lunch' => 'nullable|string', 
                'delivery_times.dinner' => 'nullable|string',
                'delivery_times.snack' => 'nullable|string',
                'skip_weekends' => 'nullable|boolean',
                'auto_schedule' => 'nullable|boolean',
                'delivery_address_id' => 'nullable|string',
                'delivery_notes' => 'nullable|string|max:500'
            ]);

            $user = $session->user;
            
            // Schedule deliveries with NestJS
            $result = $this->nestjsService->scheduleAIMealDeliveries($session, $user, $deliveryPreferences);
            
            Log::info('[API] ✅ AI meal deliveries scheduled successfully', [
                'session_id' => $sessionId,
                'delivery_ids_count' => count($result['data']['delivery_ids'] ?? [])
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'AI meal deliveries scheduled successfully',
                'data' => [
                    'session_id' => $sessionId,
                    'scheduled_at' => now()->toIso8601String(),
                    'delivery_status' => 'scheduled',
                    'total_deliveries' => count($result['data']['delivery_ids'] ?? []),
                    'estimated_start_date' => now()->addDay()->format('Y-m-d'),
                    'nestjs_profile_id' => $result['data']['profile_id'] ?? null
                ]
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('[API] ❌ Schedule Delivery Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to schedule delivery',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Update meal consumption status
     */
    public function updateConsumption(Request $request, $sessionId)
    {
        try {
            Log::info('[API] 🍽️ Updating meal consumption', ['session_id' => $sessionId]);
            
            $session = MealSession::find($sessionId);
            
            if (!$session) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session not found'
                ], 404);
            }

            $consumptionData = $request->validate([
                'day' => 'required|integer|min:1',
                'meal_type' => 'required|in:breakfast,lunch,dinner,snack,coffee,dessert',
                'consumed' => 'required|boolean',
                'consumed_at' => 'nullable|date',
                'rating' => 'nullable|integer|min:1|max:5',
                'feedback' => 'nullable|string|max:500'
            ]);

            // Update consumption via NestJS
            $result = $this->nestjsService->updateMealConsumption($session, [$consumptionData]);
            
            return response()->json([
                'success' => true,
                'message' => 'Meal consumption updated successfully',
                'data' => [
                    'session_id' => $sessionId,
                    'updated_at' => now()->toIso8601String(),
                    'consumption_rate' => $session->fresh()->consumption_rate
                ]
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error', 
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('[API] ❌ Update Consumption Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update consumption',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get delivery status for meal session
     */
    public function getDeliveryStatus(Request $request, $sessionId)
    {
        try {
            Log::info('[API] 📊 Getting delivery status', ['session_id' => $sessionId]);
            
            $session = MealSession::find($sessionId);
            
            if (!$session) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session not found'
                ], 404);
            }

            // Get latest status from NestJS
            $deliveryStatus = $this->nestjsService->getDeliveryStatus($session);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'session_id' => $sessionId,
                    'delivery_scheduled' => $session->delivery_scheduled ?? false,
                    'delivery_status' => $session->delivery_status ?? 'not_scheduled',
                    'scheduled_at' => $session->delivery_scheduled_at,
                    'total_meals_delivered' => $session->total_meals_delivered ?? 0,
                    'total_meals_consumed' => $session->total_meals_consumed ?? 0,
                    'consumption_rate' => $session->consumption_rate ?? 0.0,
                    'consumption_tracking' => json_decode($session->consumption_tracking ?? '[]', true),
                    'delivery_details' => $deliveryStatus['data'] ?? null,
                    'last_sync' => $session->last_delivery_sync
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('[API] ❌ Get Delivery Status Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get delivery status',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * NestJS webhook handler for delivery status updates
     */
    public function deliveryStatusWebhook(Request $request)
    {
        try {
            Log::info('[API] 🔔 Processing delivery status webhook');
            
            $webhookData = $request->validate([
                'laravel_session_id' => 'required|string',
                'delivery_status' => 'nullable|in:scheduled,in_progress,completed,failed',
                'delivered_count' => 'nullable|integer',
                'consumption_data' => 'nullable|array',
                'event_type' => 'required|in:delivery_status,consumption_update',
                'timestamp' => 'required|date'
            ]);

            $result = $this->nestjsService->handleDeliveryStatusWebhook($webhookData);
            
            return response()->json($result);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid webhook data',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('[API] ❌ Webhook Handler Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Webhook processing failed',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Download meal plan as PDF
     */
    public function downloadPdf(Request $request, $userIdentifier)
    {
        try {
            // Find user
            $user = User::where('user_hash', $userIdentifier)
                ->orWhere('device_id', $userIdentifier)
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Get latest completed session
            $session = $user->mealSessions()
                ->where('status', 'completed')
                ->latest()
                ->first();

            if (!$session) {
                return response()->json([
                    'success' => false,
                    'message' => 'No completed meal plan found'
                ], 404);
            }

            // Prepare data for PDF
            $data = [
                'meal_plan' => $session->meal_data,
                'user_info' => [
                    'age' => $user->age,
                    'height' => $user->height,
                    'weight' => $user->weight,
                    'gender' => $user->gender,
                    'activity_level' => $user->activity_level,
                    'bmi' => $user->bmi,
                    'bmi_overview' => $user->bmi_overview,
                    'bmr' => $user->bmr,
                    'tdee' => $user->tdee,
                    'body_fat' => $user->body_fat
                ],
                'current_day' => $session->total_days,
                'goal_decision' => $session->goal,
                'goal_explanation' => $session->goal_explanation,
                'generated_date' => $session->completed_at->format('F j, Y'),
                'total_days' => $session->total_days,
                'summary' => [
                    'total_cal' => $session->total_calories,
                    'total_protein' => $session->total_protein,
                    'total_carbs' => $session->total_carbs,
                    'total_fat' => $session->total_fat,
                    'total_price' => $session->total_price,
                    'total_meals' => $session->total_meals,
                    'avg_cal_per_day' => $session->total_days > 0 
                        ? round($session->total_calories / $session->total_days, 2) 
                        : 0,
                    'avg_protein_per_day' => $session->total_days > 0 
                        ? round($session->total_protein / $session->total_days, 2) 
                        : 0,
                    'avg_carbs_per_day' => $session->total_days > 0 
                        ? round($session->total_carbs / $session->total_days, 2) 
                        : 0,
                    'avg_fat_per_day' => $session->total_days > 0 
                        ? round($session->total_fat / $session->total_days, 2) 
                        : 0,
                    'avg_price_per_day' => $session->total_days > 0 
                        ? round($session->total_price / $session->total_days, 2) 
                        : 0,
                ]
            ];

            // Generate PDF
            $pdf = \PDF::loadView('pdf.meal-plan', $data);
            $pdf->setPaper('A4', 'portrait');

            $filename = 'meal-plan-' . $user->user_hash . '-' . now()->format('Y-m-d') . '.pdf';

            return $pdf->download($filename);

        } catch (\Exception $e) {
            Log::error('Download PDF Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate PDF',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}