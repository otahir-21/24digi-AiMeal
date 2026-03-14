<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MealSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SessionDebugController extends Controller
{
    /**
     * Get all sessions with their status
     */
    public function getAllSessions(Request $request)
    {
        $sessions = MealSession::with('user')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();
            
        $stats = [
            'total' => $sessions->count(),
            'pending' => $sessions->where('status', 'pending')->count(),
            'processing' => $sessions->where('status', 'processing')->count(),
            'completed' => $sessions->where('status', 'completed')->count(),
            'failed' => $sessions->where('status', 'failed')->count(),
        ];
        
        $sessionData = $sessions->map(function ($session) {
            $minutesSinceUpdate = $session->updated_at->diffInMinutes(now());
            $isStuck = ($session->status === 'processing' && $minutesSinceUpdate > 10) ||
                      ($session->status === 'processing' && $session->current_day === 0 && $minutesSinceUpdate > 5);
            
            return [
                'id' => $session->id,
                'user_id' => $session->user_id,
                'status' => $session->status,
                'current_day' => $session->current_day,
                'total_days' => $session->total_days,
                'progress_percentage' => $session->total_days > 0 
                    ? round(($session->current_day / $session->total_days) * 100, 2) 
                    : 0,
                'is_stuck' => $isStuck,
                'minutes_since_update' => $minutesSinceUpdate,
                'started_at' => $session->started_at,
                'updated_at' => $session->updated_at,
                'completed_at' => $session->completed_at,
                'error_message' => $session->error_message
            ];
        });
        
        return response()->json([
            'success' => true,
            'stats' => $stats,
            'sessions' => $sessionData
        ]);
    }
    
    /**
     * Get stuck sessions
     */
    public function getStuckSessions(Request $request)
    {
        $stuckThresholdMinutes = $request->get('threshold', 10);
        
        // Find sessions that haven't been updated recently
        $stuckSessions = MealSession::where('status', 'processing')
            ->where('updated_at', '<', Carbon::now()->subMinutes($stuckThresholdMinutes))
            ->get();
            
        // Find sessions stuck at day 0
        $zeroProgressSessions = MealSession::where('status', 'processing')
            ->where('current_day', 0)
            ->where('created_at', '<', Carbon::now()->subMinutes(5))
            ->get();
            
        $allStuck = $stuckSessions->merge($zeroProgressSessions)->unique('id');
        
        $data = $allStuck->map(function ($session) {
            return [
                'id' => $session->id,
                'user_id' => $session->user_id,
                'status' => $session->status,
                'current_day' => $session->current_day,
                'total_days' => $session->total_days,
                'stuck_reason' => $session->current_day === 0 ? 'Never started' : 'Stalled during generation',
                'minutes_stuck' => $session->updated_at->diffInMinutes(now()),
                'hours_stuck' => round($session->updated_at->diffInHours(now()), 2),
                'started_at' => $session->started_at,
                'last_updated' => $session->updated_at
            ];
        });
        
        return response()->json([
            'success' => true,
            'stuck_count' => $data->count(),
            'sessions' => $data
        ]);
    }
    
    /**
     * Cancel a stuck session
     */
    public function cancelSession(Request $request, $sessionId)
    {
        $session = MealSession::find($sessionId);
        
        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Session not found'
            ], 404);
        }
        
        if (!in_array($session->status, ['pending', 'processing'])) {
            return response()->json([
                'success' => false,
                'message' => 'Session is not active (status: ' . $session->status . ')'
            ], 400);
        }
        
        $previousStatus = $session->status;
        $previousDay = $session->current_day;
        
        $session->update([
            'status' => 'failed',
            'error_message' => 'Session cancelled via debug API'
        ]);
        
        Log::warning('[DEBUG] Session cancelled', [
            'session_id' => $sessionId,
            'previous_status' => $previousStatus,
            'previous_day' => $previousDay,
            'cancelled_by' => $request->ip()
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'Session cancelled successfully',
            'session' => [
                'id' => $session->id,
                'previous_status' => $previousStatus,
                'new_status' => 'failed',
                'stuck_at_day' => $previousDay
            ]
        ]);
    }
    
    /**
     * Cleanup all stuck sessions
     */
    public function cleanupStuckSessions(Request $request)
    {
        $dryRun = $request->get('dry_run', true);
        $thresholdMinutes = $request->get('threshold', 30);
        
        // Find stuck sessions
        $stuckSessions = MealSession::where('status', 'processing')
            ->where('updated_at', '<', Carbon::now()->subMinutes($thresholdMinutes))
            ->get();
            
        // Find sessions stuck at day 0
        $zeroProgressSessions = MealSession::where('status', 'processing')
            ->where('current_day', 0)
            ->where('created_at', '<', Carbon::now()->subMinutes(10))
            ->get();
            
        $allStuck = $stuckSessions->merge($zeroProgressSessions)->unique('id');
        
        $cleaned = [];
        
        foreach ($allStuck as $session) {
            $cleaned[] = [
                'id' => $session->id,
                'user_id' => $session->user_id,
                'stuck_at_day' => $session->current_day,
                'minutes_stuck' => $session->updated_at->diffInMinutes(now())
            ];
            
            if (!$dryRun) {
                $session->update([
                    'status' => 'failed',
                    'error_message' => 'Session timed out - cleaned by debug API'
                ]);
                
                Log::warning('[DEBUG] Cleaned stuck session', [
                    'session_id' => $session->id,
                    'stuck_at_day' => $session->current_day,
                    'minutes_stuck' => $session->updated_at->diffInMinutes(now())
                ]);
            }
        }
        
        return response()->json([
            'success' => true,
            'dry_run' => $dryRun,
            'sessions_cleaned' => count($cleaned),
            'sessions' => $cleaned,
            'message' => $dryRun 
                ? 'Dry run - no changes made. Set dry_run=false to actually clean sessions' 
                : 'Sessions cleaned successfully'
        ]);
    }
    
    /**
     * Get user's session history
     */
    public function getUserSessions(Request $request, $userId)
    {
        $user = User::find($userId);
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }
        
        $sessions = $user->mealSessions()
            ->orderBy('created_at', 'desc')
            ->get();
            
        $data = $sessions->map(function ($session) {
            return [
                'id' => $session->id,
                'status' => $session->status,
                'progress' => "{$session->current_day}/{$session->total_days}",
                'progress_percentage' => $session->total_days > 0 
                    ? round(($session->current_day / $session->total_days) * 100, 2) 
                    : 0,
                'started_at' => $session->started_at,
                'completed_at' => $session->completed_at,
                'duration_minutes' => $session->completed_at 
                    ? $session->started_at->diffInMinutes($session->completed_at)
                    : null,
                'error' => $session->error_message
            ];
        });
        
        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'hash' => $user->user_hash,
                'total_sessions' => $sessions->count(),
                'completed_sessions' => $sessions->where('status', 'completed')->count(),
                'failed_sessions' => $sessions->where('status', 'failed')->count()
            ],
            'sessions' => $data
        ]);
    }
}