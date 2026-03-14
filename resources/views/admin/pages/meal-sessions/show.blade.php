<x-app-layout>
    <div class="mb-6">
        <a href="{{ route('meal-sessions.index') }}" class="text-[#7F5AF0] hover:underline">
            ← Back to Meal Plans
        </a>
    </div>

    <div class="bg-white rounded-2xl p-6 mb-6">
        <h2 class="text-2xl font-semibold mb-4">Meal Plan Details</h2>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <p class="text-gray-600">User:</p>
                <p class="font-semibold">{{ $mealSession->user->name ?? 'N/A' }}</p>
                <p class="text-sm text-gray-500">{{ $mealSession->user->email ?? $mealSession->user->user_hash }}</p>
            </div>

            <div>
                <p class="text-gray-600">Status:</p>
                <span
                    class="px-3 py-1 rounded-full text-xs font-semibold inline-block
                    @if ($mealSession->status === 'completed') bg-green-200 text-green-800
                    @elseif($mealSession->status === 'processing') bg-blue-200 text-blue-800
                    @elseif($mealSession->status === 'failed') bg-red-200 text-red-800
                    @else bg-gray-200 text-gray-800 @endif">
                    {{ ucfirst($mealSession->status) }}
                </span>
            </div>

            <div>
                <p class="text-gray-600">Goal:</p>
                <p class="font-semibold">{{ ucfirst($mealSession->goal ?? 'N/A') }}</p>
            </div>

            <div>
                <p class="text-gray-600">Days:</p>
                <p class="font-semibold">{{ $mealSession->current_day }} / {{ $mealSession->total_days }}</p>
            </div>

            <div>
                <p class="text-gray-600">Total Meals:</p>
                <p class="font-semibold">{{ $mealSession->total_meals ?? 0 }}</p>
            </div>

            <div>
                <p class="text-gray-600">Created:</p>
                <p class="font-semibold">{{ $mealSession->created_at->format('M d, Y H:i') }}</p>
            </div>

            @if ($mealSession->total_calories)
                <div>
                    <p class="text-gray-600">Total Calories:</p>
                    <p class="font-semibold">{{ number_format($mealSession->total_calories, 2) }} kcal</p>
                </div>
            @endif

            @if ($mealSession->total_protein)
                <div>
                    <p class="text-gray-600">Total Protein:</p>
                    <p class="font-semibold">{{ number_format($mealSession->total_protein, 2) }}g</p>
                </div>
            @endif

            @if ($mealSession->total_carbs)
                <div>
                    <p class="text-gray-600">Total Carbs:</p>
                    <p class="font-semibold">{{ number_format($mealSession->total_carbs, 2) }}g</p>
                </div>
            @endif

            @if ($mealSession->total_fat)
                <div>
                    <p class="text-gray-600">Total Fat:</p>
                    <p class="font-semibold">{{ number_format($mealSession->total_fat, 2) }}g</p>
                </div>
            @endif

            @if ($mealSession->total_price)
                <div>
                    <p class="text-gray-600">Total Price:</p>
                    <p class="font-semibold">${{ number_format($mealSession->total_price, 2) }}</p>
                </div>
            @endif
        </div>

        @if ($mealSession->goal_explanation)
            <div class="mt-4">
                <p class="text-gray-600">Goal Explanation:</p>
                <p class="text-sm">{{ $mealSession->goal_explanation }}</p>
            </div>
        @endif

        @if ($mealSession->error_message)
            <div class="mt-4 p-3 bg-red-100 rounded">
                <p class="text-gray-600">Error Message:</p>
                <p class="text-sm text-red-700">{{ $mealSession->error_message }}</p>
            </div>
        @endif
    </div>

    @if ($mealSession->meal_data)
        <div class="bg-white rounded-2xl p-6">
            <h3 class="text-xl font-semibold mb-4">Meal Plan Breakdown</h3>
            {{-- {{ dd(json_decode($mealSession->meal_data, true)) }} --}}
            @foreach (json_decode($mealSession->meal_data, true) as $dayIndex => $dayData)
                <div class="mb-6 border-b pb-4">
                    <h4 class="text-lg font-semibold mb-3">Day {{ $dayIndex + 1 }}</h4>

                    {{-- @if (isset($dayData['meals'])) --}}
                    @if (isset($dayData))
                        @foreach ($dayData as $meal)
                            <div class="ml-4 mb-3 p-3 bg-gray-50 rounded">
                                <p class="font-semibold">{{ $meal['type'] ?? ($meal['meal_type'] ?? 'N/A') }}</p>
                                <p class="text-sm">{{ $meal['name'] ?? ($meal['meal_name'] ?? 'N/A') }}</p>
                                <p class="text-xs text-gray-600">
                                    Calories: {{ $meal['total_cal'] ?? ($meal['total_calories'] ?? 0) }} |
                                    Protein: {{ $meal['total_protein'] ?? 0 }}g |
                                    Carbs: {{ $meal['total_carbs'] ?? 0 }}g |
                                    Fat: {{ $meal['total_fat'] ?? 0 }}g
                                </p>

                                @if (isset($meal['ingredients']) && count($meal['ingredients']) > 0)
                                    <div class="mt-2">
                                        <p class="text-xs font-semibold text-gray-700">Ingredients:</p>
                                        <ul class="text-xs text-gray-600 ml-4 list-disc">
                                            @foreach ($meal['ingredients'] as $ingredient)
                                                <li>{{ $ingredient['name'] ?? 'N/A' }} -
                                                    {{ $ingredient['quantity'] ?? 'N/A' }}{{ $ingredient['unit'] ?? '' }}
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif

                                @if (isset($meal['sauces']) && count($meal['sauces']) > 0)
                                    <div class="mt-2">
                                        <p class="text-xs font-semibold text-gray-700">Sauces:</p>
                                        <ul class="text-xs text-gray-600 ml-4 list-disc">
                                            @foreach ($meal['sauces'] as $sauce)
                                                <li>{{ $sauce['name'] ?? 'N/A' }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    @endif
                </div>
            @endforeach
        </div>
    @else
        <div class="bg-white rounded-2xl p-6">
            <p class="text-center text-gray-500">No meal data available yet.</p>
        </div>
    @endif
</x-app-layout>
