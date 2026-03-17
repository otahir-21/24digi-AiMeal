<?php

namespace App\Services;

use App\Models\MealSession;
use App\Models\User;
use App\Jobs\GenerateMealPlanJob;
use App\Services\RealtimeAIService;
use App\Models\Ingredient;
use App\Models\Sauce;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MealGenerationService
{
    protected $realtimeAI;

    public function __construct()
    {
        $this->realtimeAI = new RealtimeAIService();
    }

    /**
     * Create a new meal generation session with weekly generation strategy
     *
     * @param User $user
     * @param int $subscriptionMonths Duration in months (1-3)
     * @param string $strategy 'weekly' or 'all_at_once'
     * @return MealSession
     */
    public function createSession(User $user, $subscriptionMonths = 1, $strategy = 'weekly')
    {
        $totalWeeks = $subscriptionMonths * 4;

        // For weekly strategy, only generate first week initially
        $initialDays = $strategy === 'weekly' ? 7 : ($totalWeeks * 7);

        $session = MealSession::create([
            'id' => Str::uuid(),
            'user_id' => $user->id,
            'status' => 'pending',
            'current_day' => 0,
            'current_week' => 1,
            'total_days' => $initialDays, // Will be 7 for weekly strategy
            'subscription_months' => $subscriptionMonths,
            'total_weeks_planned' => $totalWeeks,
            'weeks_generated' => 0, // Incremented after first generation
            'generation_strategy' => $strategy,
            'goal' => $user->goal,
            'started_at' => now(),
            'next_generation_at' => null // Set after first week is generated
        ]);

        // Increment user's plan counter
        $user->increment('total_plans_generated');

        Log::info('[MEAL_GEN] New session created with weekly strategy', [
            'session_id' => $session->id,
            'user_id' => $user->id,
            'subscription_months' => $subscriptionMonths,
            'total_weeks_planned' => $totalWeeks,
            'initial_days' => $initialDays,
            'strategy' => $strategy,
            'goal' => $user->goal,
            'user_metrics' => [
                'bmi' => $user->bmi,
                'bmr' => $user->bmr,
                'tdee' => $user->tdee,
                'weight' => $user->weight,
                'height' => $user->height,
                'age' => $user->age,
                'gender' => $user->gender
            ]
        ]);

        return $session;
    }

    /**
     * Start meal generation (dispatch to queue or process directly)
     */
    public function startGeneration(MealSession $session)
    {
        try {
            // Update session status
            $session->update(['status' => 'processing']);

            // For immediate processing (without queue)
            // You can change this to dispatch to queue for better performance
            if (config('queue.default') === 'sync') {
                // Process synchronously
                $this->processMealGeneration($session);
            } else {
                // Dispatch to queue
                GenerateMealPlanJob::dispatch($session);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to start meal generation: ' . $e->getMessage());
            $session->update([
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Process meal generation for all days
     */
    public function processMealGeneration(MealSession $session)
    {
        Log::info('[MEAL_GEN] 🚀 Starting meal generation process', [
            'session_id' => $session->id,
            'user_id' => $session->user_id,
            'total_days' => $session->total_days,
            'goal' => $session->goal,
            'started_at' => now()->toDateTimeString()
        ]);
        
        // Set max execution time for this process (match Nginx/PHP-FPM 30 min)
        set_time_limit(1800); // 30 minutes max
        
        try {
            $user = $session->user;
            $conversationHistory = [];
            $allMeals = [];
            $dailyTotals = [];
            $totalCalories = 0;
            $totalProtein = 0;
            $totalCarbs = 0;
            $totalFat = 0;
            $totalPrice = 0;
            $totalMealsCount = 0;

            // For weekly 7-day plans, generate all days in a single multi-day call to reduce
            // API round trips and cost. Other strategies still use per-day generation below.
            if ($session->generation_strategy === 'weekly' && (int) $session->total_days === 7) {
                Log::info("[MEAL_GEN] Using multi-day generation strategy for session {$session->id}");
                $this->processMultiDayGeneration($session);
                return;
            }

            // Try to connect to Realtime API
            $useRealtime = false;
            if ($this->realtimeAI->connect()) {
                $useRealtime = true;
                Log::info("Connected to OpenAI Realtime API for session {$session->id}");
            } else {
                Log::info("Using regular API for session {$session->id}");
            }

            for ($day = 1; $day <= $session->total_days; $day++) {
                try {
                    // Update current day
                    $session->update(['current_day' => $day]);

                    // Generate prompt for current day
                    $prompt = $this->generatePrompt($user, $day, $conversationHistory);

                    // Get AI response
                    $response = null;
                    if ($useRealtime && $this->realtimeAI->isConnected()) {
                        try {
                            $response = $this->realtimeAI->generateMealPlan($prompt);
                            Log::info("[MEAL_GEN] Day {$day} - Using Realtime API");
                        } catch (\Exception $e) {
                            Log::warning("[MEAL_GEN] Realtime API failed, falling back to regular API: " . $e->getMessage());
                            $useRealtime = false;
                        }
                    }

                    if (!$response) {
                        // Use regular API
                        Log::info("[MEAL_GEN] Day {$day} - Using Regular OpenAI API");
                        $response = $this->generateWithRegularAPI($prompt, $conversationHistory);
                    }
                    
                    // Log the raw AI response
                    Log::info("[MEAL_GEN] Day {$day} - Raw AI Response:", [
                        'response_length' => strlen($response),
                        'response_preview' => substr($response, 0, 500) . '...'
                    ]);

                    // Parse response
                    $mealData = $this->parseAIResponse($response);

                    if (!$mealData) {
                        Log::error("[MEAL_GEN] Day {$day} - Failed to parse AI response", [
                            'raw_response' => $response
                        ]);
                        throw new \Exception("Invalid meal data for day {$day}");
                    }
                    
                    // Log parsed meal data structure
                    Log::info("[MEAL_GEN] Day {$day} - Parsed Meal Data:", [
                        'has_meals' => isset($mealData['meals']),
                        'meal_count' => isset($mealData['meals']) ? count($mealData['meals']) : 0,
                        'goal' => $mealData['goal'] ?? 'not set',
                        'data_keys' => array_keys($mealData)
                    ]);

                    // Store goal information (first day only)
                    if ($day === 1 && isset($mealData['goal'])) {
                        $session->update([
                            'goal' => $mealData['goal'],
                            'goal_explanation' => $mealData['goal_explanation'] ?? null
                        ]);
                    }

                    // Add meals to collection
                    if (isset($mealData['meals'])) {
                        $allMeals[] = $mealData['meals'];

                        // Log detailed meal information
                        Log::info("[MEAL_GEN] Day {$day} - Meal Details:", [
                            'meals' => array_map(function($meal) {
                                return [
                                    'type' => $meal['type'] ?? 'unknown',
                                    'name' => $meal['name'] ?? 'unknown',
                                    'calories' => $meal['total_cal'] ?? 0,
                                    'protein' => $meal['total_protein'] ?? 0,
                                    'carbs' => $meal['total_carbs'] ?? 0,
                                    'fat' => $meal['total_fat'] ?? 0,
                                    'price' => $meal['total_price'] ?? 0,
                                    'ingredients_count' => isset($meal['ingredients']) ? count($meal['ingredients']) : 0
                                ];
                            }, $mealData['meals'])
                        ]);

                        // Calculate daily totals
                        $dayTotal = $this->calculateDayTotal($mealData['meals']);
                        $dailyTotals[] = $dayTotal;
                        
                        // Log daily totals
                        Log::info("[MEAL_GEN] Day {$day} - Daily Totals:", $dayTotal);

                        // Update running totals
                        $totalCalories += $dayTotal['calories'];
                        $totalProtein += $dayTotal['protein'];
                        $totalCarbs += $dayTotal['carbs'];
                        $totalFat += $dayTotal['fat'];
                        $totalPrice += $dayTotal['price'];
                        $totalMealsCount += count($mealData['meals']);
                    }

                    // Update session with progress
                    $sessionUpdate = [
                        'meal_data' => json_encode($allMeals),
                        'daily_totals' => json_encode($dailyTotals),
                        'total_calories' => $totalCalories,
                        'total_protein' => $totalProtein,
                        'total_carbs' => $totalCarbs,
                        'total_fat' => $totalFat,
                        'total_price' => $totalPrice,
                        'total_meals' => $totalMealsCount
                    ];
                    
                    $session->update($sessionUpdate);
                    
                    // Log session update
                    Log::info("[MEAL_GEN] Day {$day} - Session Updated:", [
                        'session_id' => $session->id,
                        'days_completed' => count($allMeals),
                        'total_meals_so_far' => $totalMealsCount,
                        'running_totals' => [
                            'calories' => $totalCalories,
                            'protein' => $totalProtein,
                            'carbs' => $totalCarbs,
                            'fat' => $totalFat,
                            'price' => $totalPrice
                        ]
                    ]);

                    // Add to conversation history
                    $conversationHistory[] = ['role' => 'user', 'content' => $prompt];
                    $conversationHistory[] = ['role' => 'assistant', 'content' => $response];

                    // Keep conversation history manageable
                    if (count($conversationHistory) > 10) {
                        $conversationHistory = array_slice($conversationHistory, -10);
                    }

                    Log::info("[MEAL_GEN] ✅ Completed day {$day} for session {$session->id}", [
                        'day' => $day,
                        'session_id' => $session->id,
                        'meals_generated' => isset($mealData['meals']) ? count($mealData['meals']) : 0,
                        'progress' => "{$day}/{$session->total_days}"
                    ]);
                    // Small delay between days to avoid rate limiting
                    if ($day < $session->total_days) {
                        sleep(1);
                    }

                } catch (\Exception $e) {
                    Log::error("[MEAL_GEN] ❌ Error generating day {$day}", [
                        'session_id' => $session->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);

                    // Retry logic for transient failures
                    $retryCount = 0;
                    $maxRetries = 2;
                    $retrySuccess = false;
                    
                    while ($retryCount < $maxRetries && !$retrySuccess) {
                        $retryCount++;
                        Log::info("[MEAL_GEN] 🔄 Retrying day {$day} (attempt {$retryCount}/{$maxRetries})");
                        
                        try {
                            sleep(2); // Wait before retry
                            
                            // Try again with regular API
                            $prompt = $this->generatePrompt($user, $day, $conversationHistory);
                            $response = $this->generateWithRegularAPI($prompt, $conversationHistory);
                            $mealData = $this->parseAIResponse($response);
                            
                            if ($mealData && isset($mealData['meals'])) {
                                // Success on retry
                                $allMeals[] = $mealData['meals'];
                                $dayTotal = $this->calculateDayTotal($mealData['meals']);
                                $dailyTotals[] = $dayTotal;
                                
                                $totalCalories += $dayTotal['calories'];
                                $totalProtein += $dayTotal['protein'];
                                $totalCarbs += $dayTotal['carbs'];
                                $totalFat += $dayTotal['fat'];
                                $totalPrice += $dayTotal['price'];
                                $totalMealsCount += count($mealData['meals']);
                                
                                $session->update([
                                    'meal_data' => json_encode($allMeals),
                                    'daily_totals' => json_encode($dailyTotals),
                                    'total_calories' => $totalCalories,
                                    'total_protein' => $totalProtein,
                                    'total_carbs' => $totalCarbs,
                                    'total_fat' => $totalFat,
                                    'total_price' => $totalPrice,
                                    'total_meals' => $totalMealsCount
                                ]);
                                
                                $conversationHistory[] = ['role' => 'user', 'content' => $prompt];
                                $conversationHistory[] = ['role' => 'assistant', 'content' => $response];
                                
                                $retrySuccess = true;
                                Log::info("[MEAL_GEN] ✅ Retry successful for day {$day}");
                            }
                        } catch (\Exception $retryException) {
                            Log::error("[MEAL_GEN] Retry {$retryCount} failed for day {$day}: " . $retryException->getMessage());
                        }
                    }
                    
                    if (!$retrySuccess) {
                        // If early days fail after retries, mark as failed
                        if ($day <= 3) {
                            throw new \Exception("Failed to generate day {$day} after {$maxRetries} retries: " . $e->getMessage());
                        }
                        // Otherwise, log error but continue with remaining days
                        Log::warning("[MEAL_GEN] Skipping day {$day} after failures, continuing with remaining days");
                    }
                }
            }

            // Update status based on generation strategy
            $weeksGenerated = ceil(count($allMeals) / 7);
            $isFullyCompleted = $weeksGenerated >= $session->total_weeks_planned;

            $sessionUpdate = [
                'weeks_generated' => $weeksGenerated,
                'completed_at' => now()
            ];

            if ($session->generation_strategy === 'weekly' && !$isFullyCompleted) {
                // Partially completed - schedule next week generation
                $sessionUpdate['status'] = 'partially_completed';
                $sessionUpdate['next_generation_at'] = now()->addDays(5); // Generate next week on day 5
            } else {
                // Fully completed
                $sessionUpdate['status'] = 'completed';
            }

            $session->update($sessionUpdate);

            // Log final summary
            Log::info("[MEAL_GEN] 🎉 GENERATION COMPLETED - Final Summary:", [
                'session_id' => $session->id,
                'user_id' => $user->id,
                'total_days' => $session->total_days,
                'total_meals_generated' => $totalMealsCount,
                'goal' => $session->goal,
                'final_totals' => [
                    'calories' => $totalCalories,
                    'protein' => $totalProtein,
                    'carbs' => $totalCarbs,
                    'fat' => $totalFat,
                    'price' => $totalPrice
                ],
                'averages_per_day' => [
                    'avg_calories' => round($totalCalories / $session->total_days, 2),
                    'avg_protein' => round($totalProtein / $session->total_days, 2),
                    'avg_carbs' => round($totalCarbs / $session->total_days, 2),
                    'avg_fat' => round($totalFat / $session->total_days, 2),
                    'avg_price' => round($totalPrice / $session->total_days, 2)
                ],
                'generation_completed_at' => now()->toDateTimeString()
            ]);
            
            // Log the complete meal plan structure for verification
            Log::info("[MEAL_GEN] Complete Meal Plan Structure:", [
                'session_id' => $session->id,
                'meal_plan' => array_map(function($dayMeals, $index) {
                    return [
                        'day' => $index + 1,
                        'meal_count' => count($dayMeals),
                        'meal_types' => array_map(function($meal) {
                            return $meal['type'] ?? 'unknown';
                        }, $dayMeals)
                    ];
                }, $allMeals, array_keys($allMeals))
            ]);

        } catch (\Exception $e) {
            Log::error("[MEAL_GEN] ❌ GENERATION FAILED:", [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString(),
                'failed_at_day' => $day ?? 'unknown',
                'days_completed' => count($allMeals)
            ]);

            $session->update([
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);

            throw $e;
        } finally {
            // Disconnect Realtime API if connected
            if ($useRealtime) {
                $this->realtimeAI->disconnect();
            }
        }
    }

    /**
     * Generate all 7 days for a weekly plan in a single OpenAI call.
     * Expected JSON shape:
     * {
     *   "goal": "...",
     *   "days": [
     *     { "day": 1, "meals": [...], "daily_total": {...} },
     *     ...
     *   ]
     * }
     */
    private function processMultiDayGeneration(MealSession $session): void
    {
        Log::info("[MEAL_GEN] 🔄 Starting multi-day generation for session {$session->id}", [
            'total_days' => $session->total_days,
        ]);

        $user = $session->user;

        // Reuse the single-day prompt as a base, but instruct the model to return all days at once
        $basePrompt = $this->generatePrompt($user, 1, []);

        $multiDayPrompt = $basePrompt . "\n\n"
            . "IMPORTANT: Instead of generating ONLY day 1, generate meal plans for ALL days 1 to {$session->total_days}.\n"
            . "Return a single JSON object with this exact structure:\n"
            . "{\n"
            . "  \"goal\": \"lose/gain/maintain\",\n"
            . "  \"days\": [\n"
            . "    {\n"
            . "      \"day\": 1,\n"
            . "      \"meals\": [...],\n"
            . "      \"daily_total\": {\"calories\": 0, \"protein\": 0, \"carbs\": 0, \"fat\": 0, \"price\": 0}\n"
            . "    },\n"
            . "    { \"day\": 2, ... },\n"
            . "    ... up to day {$session->total_days} ...\n"
            . "  ]\n"
            . "}\n"
            . "Each element in \"days\" must follow the same meal structure you normally return for a single day.\n";

        // Single OpenAI call for all days
        $response = $this->generateWithRegularAPI($multiDayPrompt, []);

        $cleaned = $this->cleanJsonLikeResponse($response);
        $data = json_decode($cleaned, true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['days']) || !is_array($data['days'])) {
            Log::error('[MEAL_GEN] Multi-day generation returned invalid JSON', [
                'json_error' => json_last_error_msg(),
                'raw' => $response,
                'cleaned' => $cleaned,
            ]);
            throw new \Exception('Invalid JSON structure for multi-day generation');
        }

        $allMeals = [];
        $dailyTotals = [];
        $totalCalories = 0;
        $totalProtein = 0;
        $totalCarbs = 0;
        $totalFat = 0;
        $totalPrice = 0;
        $totalMealsCount = 0;

        // Store goal (from top-level or first day)
        if (!empty($data['goal'])) {
            $session->update([
                'goal' => $data['goal'],
                'goal_explanation' => $data['goal_explanation'] ?? null,
            ]);
        }

        foreach ($data['days'] as $index => $dayEntry) {
            $dayNumber = isset($dayEntry['day']) ? (int) $dayEntry['day'] : $index + 1;

            // Update current day for tracking
            $session->update(['current_day' => $dayNumber]);

            $mealsForDay = $dayEntry['meals'] ?? [];
            if (!is_array($mealsForDay)) {
                $mealsForDay = [];
            }

            $allMeals[] = $mealsForDay;

            // Use provided daily_total if present, otherwise calculate from meals
            if (isset($dayEntry['daily_total']) && is_array($dayEntry['daily_total'])) {
                $dayTotal = [
                    'calories' => (float) ($dayEntry['daily_total']['calories'] ?? 0),
                    'protein' => (float) ($dayEntry['daily_total']['protein'] ?? 0),
                    'carbs' => (float) ($dayEntry['daily_total']['carbs'] ?? 0),
                    'fat' => (float) ($dayEntry['daily_total']['fat'] ?? 0),
                    'price' => (float) ($dayEntry['daily_total']['price'] ?? 0),
                ];
            } else {
                $dayTotal = $this->calculateDayTotal($mealsForDay);
            }

            $dailyTotals[] = $dayTotal;

            $totalCalories += $dayTotal['calories'];
            $totalProtein += $dayTotal['protein'];
            $totalCarbs += $dayTotal['carbs'];
            $totalFat += $dayTotal['fat'];
            $totalPrice += $dayTotal['price'];
            $totalMealsCount += count($mealsForDay);

            Log::info("[MEAL_GEN] Multi-day - processed day {$dayNumber} for session {$session->id}", [
                'meal_count' => count($mealsForDay),
                'day_total' => $dayTotal,
            ]);
        }

        // Final session update
        $session->update([
            'meal_data' => json_encode($allMeals),
            'daily_totals' => json_encode($dailyTotals),
            'total_calories' => $totalCalories,
            'total_protein' => $totalProtein,
            'total_carbs' => $totalCarbs,
            'total_fat' => $totalFat,
            'total_price' => $totalPrice,
            'total_meals' => $totalMealsCount,
            'current_day' => $session->total_days,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        Log::info("[MEAL_GEN] 🎉 MULTI-DAY GENERATION COMPLETED", [
            'session_id' => $session->id,
            'total_days' => $session->total_days,
            'total_meals_generated' => $totalMealsCount,
            'totals' => [
                'calories' => $totalCalories,
                'protein' => $totalProtein,
                'carbs' => $totalCarbs,
                'fat' => $totalFat,
                'price' => $totalPrice,
            ],
        ]);
    }

    /**
     * Clean a potentially markdown-wrapped JSON response (similar to WebController::cleanJsonResponse).
     */
    private function cleanJsonLikeResponse(string $response): string
    {
        $response = trim($response);

        // Remove ```json or ``` fences if present
        $response = preg_replace('/^```json\s*/i', '', $response);
        $response = preg_replace('/^```\s*/', '', $response);
        $response = preg_replace('/\s*```$/', '', $response);

        $response = trim($response);

        // Extract from first { to last } to remove any stray text
        $firstBrace = strpos($response, '{');
        $lastBrace = strrpos($response, '}');

        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $response = substr($response, $firstBrace, $lastBrace - $firstBrace + 1);
        }

        return trim($response);
    }

    /**
     * Generate prompt for AI
     */
    private function generatePrompt(User $user, $day, $conversationHistory = [])
    {
        // Get ingredients and sauces
        $ingredients = Ingredient::select('name', 'calories', 'protein', 'carbs', 'fats_per_100g', 'price')
            ->limit(100)
            ->get();

        $sauces = Sauce::select('name', 'calories', 'protein', 'carbs', 'fats_per_100g', 'price')
            ->limit(30)
            ->get();

        // Format ingredients and sauces for prompt
        $ingredientList = [];
        foreach ($ingredients as $ing) {
            $ingredientList[] = "{$ing->name}|{$ing->calories}cal|{$ing->protein}p|{$ing->carbs}c|{$ing->fats_per_100g}f|{$ing->price}$";
        }

        $sauceList = [];
        foreach ($sauces as $sauce) {
            $sauceList[] = "{$sauce->name}|{$sauce->calories}cal|{$sauce->protein}p|{$sauce->carbs}c|{$sauce->fats_per_100g}f|{$sauce->price}$";
        }

        // Determine meal count based on BMI
        $mealCount = $this->getMealCountByBMI($user->bmi);

        // Format dietary restrictions and health information
        $dietaryRestrictions = [];
        
        // Add food allergies
        if ($user->food_allergies) {
            $allergies = is_array($user->food_allergies) ? $user->food_allergies : json_decode($user->food_allergies, true);
            if (!empty($allergies)) {
                if (isset($allergies['redMeatAllergy']) && $allergies['redMeatAllergy']) {
                    $dietaryRestrictions[] = "NO RED MEAT (beef, lamb, pork)";
                }
                if (isset($allergies['milkProductAllergy']) && $allergies['milkProductAllergy']) {
                    $dietaryRestrictions[] = "NO DAIRY PRODUCTS (milk, cheese, yogurt, butter)";
                }
                if (isset($allergies['customAllergies']) && is_array($allergies['customAllergies'])) {
                    foreach ($allergies['customAllergies'] as $allergy) {
                        $dietaryRestrictions[] = "NO " . strtoupper($allergy);
                    }
                }
            }
        }

        // Add health issues considerations
        $healthConsiderations = [];
        if ($user->health_issues && is_array($user->health_issues)) {
            foreach ($user->health_issues as $issue) {
                switch (strtolower($issue)) {
                    case 'diabetes':
                        $healthConsiderations[] = "LOW SUGAR - avoid high glycemic ingredients";
                        break;
                    case 'bp':
                    case 'blood pressure':
                        $healthConsiderations[] = "LOW SODIUM - limit salt and high-sodium ingredients";
                        break;
                    case 'obesity':
                        $healthConsiderations[] = "CALORIE CONTROLLED - focus on lower calorie density foods";
                        break;
                    default:
                        $healthConsiderations[] = "Consider " . strtoupper($issue) . " dietary needs";
                }
            }
        }

        // Add workout habits for calorie adjustment
        $workoutInfo = "";
        if ($user->workout_habits && is_array($user->workout_habits) && count($user->workout_habits) > 0) {
            $workoutInfo = "Workout Frequency: " . implode(', ', $user->workout_habits) . " - adjust portions accordingly";
        }

        // Build dietary restrictions text
        $dietaryText = "";
        if (!empty($dietaryRestrictions)) {
            $dietaryText = "\nDIETARY RESTRICTIONS: " . implode(', ', $dietaryRestrictions);
        }
        if (!empty($healthConsiderations)) {
            $dietaryText .= "\nHEALTH CONSIDERATIONS: " . implode(', ', $healthConsiderations);
        }
        if (!empty($workoutInfo)) {
            $dietaryText .= "\n" . $workoutInfo;
        }

        // Build prompt
        $prompt = "Nutritionist Day {$day}. User: {$user->gender}, {$user->weight}kg, BMI {$user->bmi} ({$user->bmi_overview}), TDEE {$user->tdee}
{$dietaryText}

INGREDIENTS: " . implode(' | ', $ingredientList) . "

SAUCES: " . implode(' | ', $sauceList) . "

RULES:
1. Use EXACT names from lists above
2. STRICTLY FOLLOW all dietary restrictions and health considerations listed above
3. Create EXACTLY 7 items for {$user->goal} weight goal:
   - 1 COFFEE/BEVERAGE: morning coffee or tea (06:30)
   - 3 MAIN MEALS: breakfast (07:00), lunch (12:30), dinner (19:00)
   - 2 SNACKS: mid-morning snack (10:00), afternoon snack (15:00)
   - 1 DESSERT: evening dessert (20:30)
4. Never repeat ingredient/sauce in same day
5. Target calories: " . $this->getTargetCalories($user) . "
6. Vary meals from previous days
7. MUST include all 7 items every day - no exceptions
8. Coffee can include: black coffee, coffee with milk, tea, or healthy morning beverage (respect dairy restrictions)
9. Dessert should be sweet using fruits, yogurt, or small portions of treats (respect allergies and health needs)
10. IMPORTANT: Generate EXACTLY 7 items - 3 meals, 2 snacks, 1 dessert, 1 coffee
11. CRITICAL: Completely avoid any ingredients that conflict with dietary restrictions or health issues

JSON FORMAT ONLY:
{
    \"goal\": \"{$user->goal}\",
    \"day\": {$day},
    \"meals\": [
        {
            \"type\": \"coffee\",
            \"name\": \"Morning Coffee\",
            \"time\": \"06:30\",
            \"ingredients\": [{\"name\": \"exact_name\", \"amount\": \"200ml\", \"cal\": 50, \"protein\": 3, \"carbs\": 5, \"fat\": 2, \"price\": 2}],
            \"sauces\": [],
            \"instructions\": \"Preparation steps\",
            \"total_cal\": 50,
            \"total_protein\": 3,
            \"total_carbs\": 5,
            \"total_fat\": 2,
            \"total_price\": 2
        },
        {
            \"type\": \"breakfast\",
            \"name\": \"Breakfast Meal\",
            \"time\": \"07:00\",
            \"ingredients\": [{\"name\": \"exact_name\", \"amount\": \"100g\", \"cal\": 300, \"protein\": 20, \"carbs\": 30, \"fat\": 10, \"price\": 5}],
            \"sauces\": [{\"name\": \"exact_name\", \"amount\": \"1tbsp\", \"cal\": 20, \"protein\": 0, \"carbs\": 5, \"fat\": 0, \"price\": 1}],
            \"instructions\": \"Preparation steps\",
            \"total_cal\": 320,
            \"total_protein\": 20,
            \"total_carbs\": 35,
            \"total_fat\": 10,
            \"total_price\": 6
        },
        {
            \"type\": \"snack\",
            \"name\": \"Mid-Morning Snack\",
            \"time\": \"10:00\",
            \"ingredients\": [...],
            \"sauces\": [...],
            \"instructions\": \"...\",
            \"total_cal\": 150,
            \"total_protein\": 10,
            \"total_carbs\": 20,
            \"total_fat\": 5,
            \"total_price\": 3
        },
        {
            \"type\": \"lunch\",
            \"name\": \"Lunch Meal\",
            \"time\": \"12:30\",
            \"ingredients\": [...],
            \"sauces\": [...],
            \"instructions\": \"...\",
            \"total_cal\": 450,
            \"total_protein\": 35,
            \"total_carbs\": 45,
            \"total_fat\": 15,
            \"total_price\": 8
        },
        {
            \"type\": \"snack\",
            \"name\": \"Afternoon Snack\",
            \"time\": \"15:00\",
            \"ingredients\": [...],
            \"sauces\": [...],
            \"instructions\": \"...\",
            \"total_cal\": 180,
            \"total_protein\": 8,
            \"total_carbs\": 25,
            \"total_fat\": 6,
            \"total_price\": 4
        },
        {
            \"type\": \"dinner\",
            \"name\": \"Dinner Meal\",
            \"time\": \"19:00\",
            \"ingredients\": [...],
            \"sauces\": [...],
            \"instructions\": \"...\",
            \"total_cal\": 500,
            \"total_protein\": 40,
            \"total_carbs\": 50,
            \"total_fat\": 18,
            \"total_price\": 10
        },
        {
            \"type\": \"dessert\",
            \"name\": \"Evening Dessert\",
            \"time\": \"20:30\",
            \"ingredients\": [{\"name\": \"fruit_name\", \"amount\": \"150g\", \"cal\": 200, \"protein\": 5, \"carbs\": 35, \"fat\": 3, \"price\": 4}],
            \"sauces\": [],
            \"instructions\": \"...\",
            \"total_cal\": 200,
            \"total_protein\": 5,
            \"total_carbs\": 35,
            \"total_fat\": 3,
            \"total_price\": 4
        }
    ],
    \"daily_total\": {
        \"calories\": 1800,
        \"protein\": 120,
        \"carbs\": 180,
        \"fat\": 60,
        \"price\": 30
    }
}";

        return $prompt;
    }

    /**
     * Generate meal plan using regular OpenAI API
     */
    private function generateWithRegularAPI($prompt, $conversationHistory)
    {
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a professional nutritionist. Always respond with valid JSON only.'
            ]
        ];

        // Add limited conversation history
        if (!empty($conversationHistory)) {
            $recentHistory = array_slice($conversationHistory, -4);
            foreach ($recentHistory as $msg) {
                $messages[] = $msg;
            }
        }

        $messages[] = ['role' => 'user', 'content' => $prompt];

        $response = \Http::withHeaders([
            'Authorization' => 'Bearer ' . env('AI_API_KEY'),
            'Content-Type' => 'application/json'
        ])->timeout(60)->post('https://api.openai.com/v1/chat/completions', [
            // Use OPENAI_MODEL from .env (e.g. gpt-4o-mini) so we can switch models without code changes
            'model' => env('OPENAI_MODEL', 'gpt-4o'),
            'messages' => $messages,
            'max_tokens' => 1500,
            'temperature' => 0.7
        ]);

        if (!$response->successful()) {
            throw new \Exception('OpenAI API request failed: ' . $response->body());
        }

        $data = $response->json();

        if (!isset($data['choices'][0]['message']['content'])) {
            throw new \Exception('Invalid OpenAI response structure');
        }

        return trim($data['choices'][0]['message']['content']);
    }

    /**
     * Parse AI response
     */
    private function parseAIResponse($response)
    {
        // Clean response (remove markdown if present)
        $response = preg_replace('/^```json\s*/m', '', $response);
        $response = preg_replace('/\s*```\s*$/m', '', $response);
        $response = trim($response);

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('JSON parse error: ' . json_last_error_msg());
            Log::error('Raw response: ' . $response);
            return null;
        }

        // Validate meal structure for 7 items
        if (isset($data['meals']) && is_array($data['meals'])) {
            $mealTypes = array_column($data['meals'], 'type');
            $requiredMainMeals = ['breakfast', 'lunch', 'dinner'];
            
            // Count each type
            $snackCount = count(array_filter($mealTypes, function($type) {
                return $type === 'snack';
            }));
            $dessertCount = count(array_filter($mealTypes, function($type) {
                return $type === 'dessert';
            }));
            $coffeeCount = count(array_filter($mealTypes, function($type) {
                return $type === 'coffee' || $type === 'beverage';
            }));
            
            // Check if we have all required items
            $hasAllMainMeals = !array_diff($requiredMainMeals, $mealTypes);
            $hasCorrectStructure = $hasAllMainMeals && 
                                  $snackCount === 2 && 
                                  $dessertCount === 1 && 
                                  $coffeeCount === 1;
            
            // Validate we have exactly 7 items
            if (!$hasCorrectStructure || count($data['meals']) !== 7) {
                Log::warning('Meal validation failed - Expected 7 items (3 meals, 2 snacks, 1 dessert, 1 coffee)', [
                    'meal_types' => $mealTypes,
                    'has_breakfast' => in_array('breakfast', $mealTypes),
                    'has_lunch' => in_array('lunch', $mealTypes),
                    'has_dinner' => in_array('dinner', $mealTypes),
                    'snack_count' => $snackCount,
                    'dessert_count' => $dessertCount,
                    'coffee_count' => $coffeeCount,
                    'total_items' => count($data['meals']),
                    'expected_total' => 7
                ]);
                
                // Log but don't fail - let the AI adjust in the next iteration
                // You could optionally retry here or request regeneration
            }
        }

        return $data;
    }

    /**
     * Calculate daily total from meals
     */
    private function calculateDayTotal($meals)
    {
        $total = [
            'calories' => 0,
            'protein' => 0,
            'carbs' => 0,
            'fat' => 0,
            'price' => 0
        ];

        foreach ($meals as $meal) {
            $total['calories'] += $meal['total_cal'] ?? 0;
            $total['protein'] += $meal['total_protein'] ?? 0;
            $total['carbs'] += $meal['total_carbs'] ?? 0;
            $total['fat'] += $meal['total_fat'] ?? 0;
            $total['price'] += $meal['total_price'] ?? 0;
        }

        return $total;
    }

    /**
     * Get meal count based on BMI
     */
    private function getMealCountByBMI($bmi)
    {
        // Always return 7 items: 3 main meals + 2 snacks + 1 dessert + 1 coffee
        return '7';
    }

    /**
     * Get target calories based on user goal
     */
    private function getTargetCalories(User $user)
    {
        $tdee = $user->tdee;

        switch ($user->goal) {
            case 'lose':
                return $tdee - 400; // Deficit for weight loss
            case 'gain':
                return $tdee + 400; // Surplus for weight gain
            default:
                return $tdee; // Maintenance
        }
    }

    /**
     * Generate next week for an existing session
     * Appends 7 days to existing meal_data
     *
     * @param MealSession $session
     * @return bool Success status
     */
    public function generateNextWeek(MealSession $session)
    {
        Log::info('[MEAL_GEN] 📅 Generating next week', [
            'session_id' => $session->id,
            'current_weeks_generated' => $session->weeks_generated,
            'total_weeks_planned' => $session->total_weeks_planned
        ]);

        // Check if we can generate more weeks
        if ($session->weeks_generated >= $session->total_weeks_planned) {
            Log::warning('[MEAL_GEN] ⚠️ Cannot generate next week - plan already completed');
            return false;
        }

        // Temporarily increase total_days for next week generation
        $startDay = ($session->weeks_generated * 7) + 1;
        $endDay = $startDay + 6; // Generate 7 days

        $session->update([
            'status' => 'processing',
            'total_days' => $endDay // Temporarily update for generation loop
        ]);

        try {
            // Get existing meal data
            $existingMeals = json_decode($session->meal_data, true) ?? [];
            $existingTotals = json_decode($session->daily_totals, true) ?? [];

            // Re-run generation for the new week
            $user = $session->user;
            $conversationHistory = [];
            $newMeals = [];
            $newTotals = [];

            // Initialize running totals from existing data
            $totalCalories = $session->total_calories ?? 0;
            $totalProtein = $session->total_protein ?? 0;
            $totalCarbs = $session->total_carbs ?? 0;
            $totalFat = $session->total_fat ?? 0;
            $totalPrice = $session->total_price ?? 0;
            $totalMealsCount = $session->total_meals ?? 0;

            // Generate 7 days
            for ($day = $startDay; $day <= $endDay; $day++) {
                $prompt = $this->generatePrompt($user, $day, $conversationHistory);
                $response = $this->generateWithRegularAPI($prompt, $conversationHistory);
                $mealData = $this->parseAIResponse($response);

                if ($mealData && isset($mealData['meals'])) {
                    $newMeals[] = $mealData['meals'];
                    $dayTotal = $this->calculateDayTotal($mealData['meals']);
                    $newTotals[] = $dayTotal;

                    $totalCalories += $dayTotal['calories'];
                    $totalProtein += $dayTotal['protein'];
                    $totalCarbs += $dayTotal['carbs'];
                    $totalFat += $dayTotal['fat'];
                    $totalPrice += $dayTotal['price'];
                    $totalMealsCount += count($mealData['meals']);

                    $conversationHistory[] = ['role' => 'user', 'content' => $prompt];
                    $conversationHistory[] = ['role' => 'assistant', 'content' => $response];
                }

                sleep(1); // Rate limiting
            }

            // Merge with existing data
            $allMeals = array_merge($existingMeals, $newMeals);
            $allTotals = array_merge($existingTotals, $newTotals);

            $weeksGenerated = $session->weeks_generated + 1;
            $isFullyCompleted = $weeksGenerated >= $session->total_weeks_planned;

            // Update session
            $session->update([
                'meal_data' => json_encode($allMeals),
                'daily_totals' => json_encode($allTotals),
                'total_calories' => $totalCalories,
                'total_protein' => $totalProtein,
                'total_carbs' => $totalCarbs,
                'total_fat' => $totalFat,
                'total_price' => $totalPrice,
                'total_meals' => $totalMealsCount,
                'weeks_generated' => $weeksGenerated,
                'status' => $isFullyCompleted ? 'completed' : 'partially_completed',
                'next_generation_at' => $isFullyCompleted ? null : now()->addDays(7),
                'total_days' => count($allMeals) // Update to actual days
            ]);

            Log::info('[MEAL_GEN] ✅ Next week generated successfully', [
                'session_id' => $session->id,
                'weeks_generated' => $weeksGenerated,
                'is_fully_completed' => $isFullyCompleted
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('[MEAL_GEN] ❌ Failed to generate next week', [
                'session_id' => $session->id,
                'error' => $e->getMessage()
            ]);

            $session->update(['status' => 'partially_completed']);
            return false;
        }
    }
}
