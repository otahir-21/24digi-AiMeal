@forelse ($mealSessions as $index => $session)
    <tr>
        <td class="border-b !border-gray-400 p-3 text-[14px] text-center">
            {{ $mealSessions->firstItem() + $index }}
        </td>
        <td class="border-b !border-gray-400 p-3 text-[14px]">
            {{ $session->user->name ?? 'N/A' }}<br>
            <span class="text-xs text-gray-500">{{ $session->user->email ?? $session->user->user_hash }} -
                {{ $session->user->nestjs_profile_id ?? 'N/A' }}</span>
        </td>
        <td class="border-b !border-gray-400 p-3 text-[14px] text-center">
            <span
                class="px-3 py-1 rounded-full text-xs font-semibold
                @if ($session->status === 'completed') bg-green-200 text-green-800
                @elseif($session->status === 'processing') bg-blue-200 text-blue-800
                @elseif($session->status === 'failed') bg-red-200 text-red-800
                @else bg-gray-200 text-gray-800 @endif">
                {{ ucfirst($session->status) }}
            </span>
        </td>
        <td class="border-b !border-gray-400 p-3 text-[14px] text-center">
            {{ ucfirst($session->goal ?? 'N/A') }}
        </td>
        <td class="border-b !border-gray-400 p-3 text-[14px] text-center">
            {{ $session->current_day }} / {{ $session->total_days }}
        </td>
        <td class="border-b !border-gray-400 p-3 text-[14px] text-center">
            {{ $session->total_meals ?? 0 }}
        </td>
        <td class="border-b !border-gray-400 p-3 text-[14px] text-center">
            {{ $session->created_at->format('M d, Y') }}
        </td>
        <td class="border-b !border-gray-400 p-3 text-[14px] text-center">
            <a href="{{ route('meal-sessions.show', $session->id) }}" class="text-[#7F5AF0] hover:underline">View</a>
        </td>
    </tr>
@empty
    <tr>
        <td colspan="8">
            <p class="text-red-500 text-center">
                No meal plans found
            </p>
        </td>
    </tr>
@endforelse
