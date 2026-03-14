<?php

namespace App\Services;

use App\Models\MealSession;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

class NestJSIntegrationService
{
    protected $nestJSBaseUrl;
    protected $apiKey;

    public function __construct()
    {
        $this->nestJSBaseUrl = config('services.nestjs.base_url', 'https://api.24digi.ae');
        $this->apiKey = config('services.nestjs.api_key', env('NESTJS_API_KEY'));
    }

    /**
     * Schedule AI meal deliveries with NestJS backend
     */
    public function scheduleAIMealDeliveries(MealSession $session, User $user, array $deliveryPreferences = [])
    {
        Log::info('[NestJS Integration] 🚀 Scheduling AI meal deliveries', [
            'session_id' => $session->id,
            'user_id' => $user->id,
            'user_hash' => $user->user_hash,
            'total_days' => $session->total_days,
            'goal' => $session->goal
        ]);

        try {
            // Prepare meal data for NestJS
            $mealPlan = json_decode($session->meal_data, true);
            $dailyTotals = json_decode($session->daily_totals, true);

            // Flatten meal plan and add day numbers for NestJS
            // Laravel stores meals as [[day1_meals], [day2_meals], ...]
            // NestJS expects [{day:1, meal...}, {day:1, meal...}, {day:2, meal...}]
            $flattenedMealPlan = [];
            foreach ($mealPlan as $dayIndex => $dayMeals) {
                $dayNumber = $dayIndex + 1;
                foreach ($dayMeals as $meal) {
                    $flattenedMealPlan[] = [
                        'day' => $dayNumber,
                        'meal_type' => $meal['type'] ?? 'meal',
                        'meal_name' => $meal['name'] ?? 'Meal',
                        'scheduled_time' => $meal['time'] ?? '12:00',
                        'ingredients' => $meal['ingredients'] ?? [],
                        'instructions' => $meal['instructions'] ?? '',
                        'calories' => $meal['total_cal'] ?? 0,
                        'protein' => $meal['total_protein'] ?? 0,
                        'carbs' => $meal['total_carbs'] ?? 0,
                        'fat' => $meal['total_fat'] ?? 0,
                        'price' => $meal['total_price'] ?? 0,
                    ];
                }
            }

            Log::info('[NestJS Integration] Flattened meal plan', [
                'original_days_count' => count($mealPlan),
                'flattened_meals_count' => count($flattenedMealPlan),
                'sample_meal' => $flattenedMealPlan[0] ?? null
            ]);

            $deliveryScheduleData = [
                'laravel_session_id' => $session->id,
                'user_identification' => [
                    'user_hash' => $user->user_hash,
                    'device_id' => $user->device_id,
                    'profile_id' => $user->nestjs_profile_id, // NestJS profile ID from registration
                ],
                'subscription_type' => 'cbyai',
                'meal_plan' => $flattenedMealPlan,
                'daily_totals' => $dailyTotals,
                'plan_metadata' => [
                    'goal' => $session->goal,
                    'goal_explanation' => $session->goal_explanation,
                    'total_days' => $session->total_days,
                    'user_metrics' => [
                        'age' => $user->age,
                        'gender' => $user->gender,
                        'weight' => $user->weight,
                        'height' => $user->height,
                        'activity_level' => $user->activity_level,
                        'dietary_restrictions' => [
                            'food_allergies' => $user->food_allergies,
                            'health_issues' => $user->health_issues,
                            'food_preferences' => $user->food_ingredients,
                        ]
                    ]
                ],
                'delivery_preferences' => array_merge([
                    'auto_schedule' => true,
                    'default_delivery_times' => [
                        'breakfast' => '07:00',
                        'lunch' => '12:00', 
                        'dinner' => '19:00',
                        'snack' => '15:00',
                        'coffee' => '06:30',
                        'dessert' => '20:30'
                    ],
                    'delivery_days' => range(1, $session->total_days),
                    'skip_weekends' => false,
                ], $deliveryPreferences)
            ];

            // Make API call to NestJS
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'X-Source' => 'laravel-meal-planner'
                ])
                ->post($this->nestJSBaseUrl . '/ai-meal-delivery/schedule', $deliveryScheduleData);

            if ($response->successful()) {
                $responseData = $response->json();

                // Check if NestJS returned an error status
                if (isset($responseData['status']) && $responseData['status'] === 'error') {
                    throw new \Exception('NestJS scheduling failed: ' . ($responseData['message'] ?? 'Unknown error'));
                }

                // Validate that we have the required data
                if (!isset($responseData['data'])) {
                    Log::error('[NestJS Integration] Missing data in response', [
                        'response' => $responseData,
                        'response_body' => $response->body()
                    ]);
                    throw new \Exception('Invalid response from NestJS: missing data key');
                }

                Log::info('[NestJS Integration] ✅ AI meal deliveries scheduled successfully', [
                    'session_id' => $session->id,
                    'nestjs_profile_id' => $responseData['data']['profile_id'] ?? null,
                    'delivery_ids' => $responseData['data']['delivery_ids'] ?? [],
                    'total_deliveries' => count($responseData['data']['delivery_ids'] ?? [])
                ]);

                // Update session with delivery information
                $session->update([
                    'delivery_scheduled' => true,
                    'delivery_scheduled_at' => now(),
                    'delivery_status' => 'scheduled',
                    'nestjs_delivery_ids' => $responseData['data']['delivery_ids'] ?? [],
                    'nestjs_profile_id' => $responseData['data']['profile_id'] ?? null,
                    'delivery_preferences' => $deliveryPreferences,
                    'last_delivery_sync' => now()
                ]);

                return [
                    'success' => true,
                    'message' => 'AI meal deliveries scheduled successfully',
                    'data' => $responseData['data']
                ];
            } else {
                throw new \Exception('NestJS API returned error: ' . $response->body());
            }

        } catch (\Exception $e) {
            Log::error('[NestJS Integration] ❌ Failed to schedule AI meal deliveries', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update session with failure status
            $session->update([
                'delivery_status' => 'failed',
                'last_delivery_sync' => now()
            ]);

            throw $e;
        }
    }

    /**
     * Update meal consumption status
     */
    public function updateMealConsumption(MealSession $session, array $consumptionData)
    {
        Log::info('[NestJS Integration] 🍽️ Updating meal consumption', [
            'session_id' => $session->id,
            'consumption_data' => $consumptionData
        ]);

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json'
                ])
                ->post($this->nestJSBaseUrl . '/ai-meal-delivery/consumption', [
                    'laravel_session_id' => $session->id,
                    'consumption_data' => $consumptionData
                ]);

            if ($response->successful()) {
                // Update local consumption tracking
                $currentTracking = json_decode($session->consumption_tracking, true) ?? [];
                $updatedTracking = array_merge($currentTracking, $consumptionData);
                
                // Calculate consumption statistics
                $totalMeals = $session->total_days * 7; // Assuming 7 meals per day
                $consumedMeals = collect($updatedTracking)->sum('consumed_count');
                $consumptionRate = $totalMeals > 0 ? ($consumedMeals / $totalMeals) * 100 : 0;

                $session->update([
                    'consumption_tracking' => $updatedTracking,
                    'total_meals_consumed' => $consumedMeals,
                    'consumption_rate' => round($consumptionRate, 2),
                    'last_delivery_sync' => now()
                ]);

                return ['success' => true, 'message' => 'Consumption updated successfully'];
            } else {
                throw new \Exception('Failed to update consumption in NestJS: ' . $response->body());
            }

        } catch (\Exception $e) {
            Log::error('[NestJS Integration] ❌ Failed to update meal consumption', [
                'session_id' => $session->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get delivery status from NestJS
     */
    public function getDeliveryStatus(MealSession $session)
    {
        try {
            $deliveryIds = json_decode($session->nestjs_delivery_ids, true) ?? [];
            
            if (empty($deliveryIds)) {
                return ['success' => false, 'message' => 'No delivery IDs found'];
            }

            $response = Http::timeout(15)
                ->withHeaders(['Authorization' => 'Bearer ' . $this->apiKey])
                ->get($this->nestJSBaseUrl . '/ai-meal-delivery/status', [
                    'laravel_session_id' => $session->id,
                    'delivery_ids' => $deliveryIds
                ]);

            if ($response->successful()) {
                $statusData = $response->json();
                
                // Update local status
                $session->update([
                    'delivery_status' => $statusData['data']['overall_status'] ?? $session->delivery_status,
                    'total_meals_delivered' => $statusData['data']['delivered_count'] ?? 0,
                    'last_delivery_sync' => now()
                ]);

                return $statusData;
            }

            return ['success' => false, 'message' => 'Failed to fetch delivery status'];

        } catch (\Exception $e) {
            Log::error('[NestJS Integration] ❌ Failed to get delivery status', [
                'session_id' => $session->id,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Handle delivery status webhook from NestJS
     */
    public function handleDeliveryStatusWebhook(array $webhookData)
    {
        Log::info('[NestJS Integration] 📥 Received delivery status webhook', $webhookData);

        try {
            $sessionId = $webhookData['laravel_session_id'] ?? null;
            if (!$sessionId) {
                throw new \Exception('Missing laravel_session_id in webhook data');
            }

            $session = \App\Models\MealSession::find($sessionId);
            if (!$session) {
                throw new \Exception("Session {$sessionId} not found");
            }

            // Update delivery status based on webhook
            $updates = [
                'last_delivery_sync' => now()
            ];

            if (isset($webhookData['delivery_status'])) {
                $updates['delivery_status'] = $webhookData['delivery_status'];
            }

            if (isset($webhookData['delivered_count'])) {
                $updates['total_meals_delivered'] = $webhookData['delivered_count'];
            }

            if (isset($webhookData['consumption_data'])) {
                $currentTracking = json_decode($session->consumption_tracking, true) ?? [];
                $updates['consumption_tracking'] = array_merge($currentTracking, $webhookData['consumption_data']);
            }

            $session->update($updates);

            Log::info('[NestJS Integration] ✅ Webhook processed successfully', [
                'session_id' => $sessionId,
                'updates' => array_keys($updates)
            ]);

            return ['success' => true, 'message' => 'Webhook processed successfully'];

        } catch (\Exception $e) {
            Log::error('[NestJS Integration] ❌ Webhook processing failed', [
                'error' => $e->getMessage(),
                'webhook_data' => $webhookData
            ]);
            throw $e;
        }
    }
}