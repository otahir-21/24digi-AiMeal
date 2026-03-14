<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\MealSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CleanupStuckSessions extends Command
{
    protected $signature = 'sessions:cleanup 
                            {--hours=2 : Number of hours before considering a session stuck}
                            {--dry-run : Run without making changes}';

    protected $description = 'Clean up stuck meal generation sessions';

    public function handle()
    {
        $hours = $this->option('hours');
        $dryRun = $this->option('dry-run');
        
        $this->info('🧹 Starting session cleanup...');
        
        // Find stuck sessions
        $stuckSessions = MealSession::where('status', 'processing')
            ->where('updated_at', '<', Carbon::now()->subHours($hours))
            ->get();
            
        if ($stuckSessions->isEmpty()) {
            $this->info('✅ No stuck sessions found.');
            return 0;
        }
        
        $this->warn("Found {$stuckSessions->count()} stuck sessions:");
        
        foreach ($stuckSessions as $session) {
            $this->line("Session ID: {$session->id}");
            $this->line("  User ID: {$session->user_id}");
            $this->line("  Current Day: {$session->current_day}/{$session->total_days}");
            $this->line("  Started: {$session->started_at}");
            $this->line("  Last Updated: {$session->updated_at}");
            $this->line("  Hours Stuck: " . $session->updated_at->diffInHours(now()));
            
            if (!$dryRun) {
                // Mark session as failed
                $session->update([
                    'status' => 'failed',
                    'error_message' => 'Session timed out - stuck for more than ' . $hours . ' hours'
                ]);
                
                Log::warning('[CLEANUP] Marked stuck session as failed', [
                    'session_id' => $session->id,
                    'user_id' => $session->user_id,
                    'stuck_at_day' => $session->current_day,
                    'hours_stuck' => $session->updated_at->diffInHours(now())
                ]);
                
                $this->info("  ❌ Marked as failed");
            } else {
                $this->info("  🔍 Would mark as failed (dry-run)");
            }
        }
        
        if (!$dryRun) {
            $this->info("✅ Cleaned up {$stuckSessions->count()} stuck sessions");
        } else {
            $this->info("🔍 Dry run complete - would clean {$stuckSessions->count()} sessions");
        }
        
        // Also check for sessions stuck at day 0
        $zeroProgressSessions = MealSession::where('status', 'processing')
            ->where('current_day', 0)
            ->where('created_at', '<', Carbon::now()->subMinutes(30))
            ->get();
            
        if ($zeroProgressSessions->isNotEmpty()) {
            $this->warn("\n⚠️ Found {$zeroProgressSessions->count()} sessions stuck at day 0:");
            
            foreach ($zeroProgressSessions as $session) {
                $this->line("Session ID: {$session->id} (created {$session->created_at->diffForHumans()})");
                
                if (!$dryRun) {
                    $session->update([
                        'status' => 'failed',
                        'error_message' => 'Session failed to start - stuck at day 0'
                    ]);
                    $this->info("  ❌ Marked as failed");
                }
            }
        }
        
        return 0;
    }
}