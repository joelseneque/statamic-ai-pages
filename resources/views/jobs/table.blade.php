<table class="data-table w-full text-sm">
    <tbody>
        @foreach ($jobs as $job)
            <tr>
                <td class="py-2">
                    <a href="{{ cp_route('ai-pages.jobs.show', $job['id']) }}" class="text-blue-600 hover:underline">
                        {{ $job['title'] ?? $job['entry_title'] ?? ucfirst($job['type'] ?? 'job') }}
                    </a>
                    <span class="block text-xs text-gray-500">
                        {{ ucfirst($job['type'] ?? '') }}
                        @isset($job['collection']) · {{ $job['collection'] }} @endisset
                        @isset($job['mode']) · {{ $job['mode'] }} @endisset
                    </span>
                </td>
                <td class="py-2 text-right whitespace-nowrap">
                    @php
                        $status = $job['status'] ?? 'unknown';
                        $colour = match ($status) {
                            'completed' => 'text-green-600',
                            'failed' => 'text-red-600',
                            'awaiting_approval' => 'text-amber-600',
                            default => 'text-gray-500',
                        };
                    @endphp
                    <span class="{{ $colour }}">{{ str_replace('_', ' ', $status) }}</span>
                    <span class="block text-xs text-gray-400">
                        {{ \Carbon\Carbon::parse($job['created_at'])->diffForHumans() }}
                    </span>
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
