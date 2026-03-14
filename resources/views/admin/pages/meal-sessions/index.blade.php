<x-app-layout>
    <div class="flex justify-between">
        <p class="text-3xl font-semibold">
            {{ __('Customer Meal Plans') }}
        </p>
        <div class="flex gap-3">
            <select id="status-filter" class="px-4 py-2 border border-[#7F5AF0] rounded-lg">
                <option value="">All Status</option>
                <option value="completed">Completed</option>
                <option value="processing">Processing</option>
                <option value="failed">Failed</option>
                <option value="pending">Pending</option>
            </select>
            <input type="text" id="search-input" placeholder="Search user..."
                class="px-4 py-2 border border-[#7F5AF0] rounded-lg">
        </div>
    </div>

    <div class="rounded-2xl overflow-hidden mt-7">
        <table class="w-full rounded p-5">
            <thead class="bg-white border-b">
                <tr>
                    <th class="p-3 text-[16px]">#</th>
                    <th class="p-2 text-[16px]">User</th>
                    <th class="p-2 text-[16px]">Status</th>
                    <th class="p-2 text-[16px]">Goal</th>
                    <th class="p-2 text-[16px]">Days</th>
                    <th class="p-2 text-[16px]">Total Meals</th>
                    <th class="p-2 text-[16px]">Created</th>
                    <th class="p-2 text-[16px]">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-[#7F5AF0]/10" id="table-body">
                @forelse ($mealSessions as $index => $session)
                    <tr>
                        <td class="border-b !border-gray-400 p-3 text-[14px] text-center">
                            {{ $mealSessions->firstItem() + $index }}
                        </td>
                        <td class="border-b !border-gray-400 p-3 text-[14px]">
                            {{ $session->user->name ?? 'N/A' }}<br>
                            <span class="text-xs text-gray-500">{{ $session->user->email ?? $session->user->user_hash }}
                                -
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
                            <a href="{{ route('meal-sessions.show', $session->id) }}"
                                class="text-[#7F5AF0] hover:underline">View</a>
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
            </tbody>
        </table>
        <div class="mt-3">
            {{ $mealSessions->links() }}
        </div>
    </div>

    @push('script')
        <script>
            jQuery(document).ready(function() {
                // Status filter
                jQuery('#status-filter').on('change', function() {
                    const status = jQuery(this).val();
                    const search = jQuery('#search-input').val();
                    fetchResults(status, search);
                });

                // Search functionality
                let searchTimeout;
                jQuery('#search-input').on('keyup', function() {
                    clearTimeout(searchTimeout);
                    const search = jQuery(this).val();
                    const status = jQuery('#status-filter').val();
                    searchTimeout = setTimeout(() => {
                        fetchResults(status, search);
                    }, 500);
                });

                function fetchResults(status, search) {
                    jQuery.ajax({
                        url: "{{ route('meal-sessions.index') }}",
                        data: {
                            status,
                            search
                        },
                        success: function(response) {
                            jQuery('#table-body').html(response.html);
                        }
                    });
                }
            });
        </script>
    @endpush
</x-app-layout>
