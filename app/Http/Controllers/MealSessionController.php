<?php

namespace App\Http\Controllers;

use App\Models\MealSession;
use Illuminate\Http\Request;

class MealSessionController extends Controller
{
    /**
     * Display a listing of customer meal plans
     */
    public function index(Request $request)
    {
        $query = MealSession::with('user')
            ->orderBy('created_at', 'desc');

        // Filter by status if provided
        if ($request->has('status') && $request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Search by user name or email
        if ($request->has('search') && $request->filled('search')) {
            $search = $request->search;
            $query->whereHas('user', function($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('email', 'LIKE', "%{$search}%")
                  ->orWhere('user_hash', 'LIKE', "%{$search}%");
            });
        }

        $mealSessions = $query->paginate(15);

        if ($request->ajax()) {
            return response()->json([
                'error' => false,
                'html' => view('admin.pages.meal-sessions.table', compact('mealSessions'))->render()
            ]);
        }

        return view('admin.pages.meal-sessions.index', compact('mealSessions'));
    }

    /**
     * Show detailed view of a specific meal plan
     */
    public function show($id)
    {
        $mealSession = MealSession::with('user')->findOrFail($id);

        return view('admin.pages.meal-sessions.show', compact('mealSession'));
    }

    /**
     * Delete a meal session
     */
    public function destroy($id)
    {
        $mealSession = MealSession::findOrFail($id);
        $mealSession->delete();

        return redirect()->back()->with('success', 'Meal plan deleted successfully');
    }
}
