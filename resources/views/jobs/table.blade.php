<div class="aip-rows">
    @foreach ($jobs as $job)
        @php $status = $job['status'] ?? 'unknown'; @endphp
        <div class="aip-row">
            <span>
                <a href="{{ cp_route('ai-pages.jobs.show', $job['id']) }}" class="aip-link">
                    {{ $job['title'] ?? $job['entry_title'] ?? ucfirst($job['type'] ?? 'job') }}
                </a>
                <span class="aip-meta" style="display:block">
                    {{ ucfirst($job['type'] ?? '') }}@isset($job['collection']) · {{ $job['collection'] }}@endisset@isset($job['mode']) · {{ $job['mode'] }}@endisset
                </span>
            </span>
            <span style="text-align:right;white-space:nowrap">
                <span class="aip-status aip-status--{{ $status }}">{{ str_replace('_', ' ', $status) }}</span>
                <span class="aip-meta" style="display:block">{{ \Carbon\Carbon::parse($job['created_at'])->diffForHumans() }}</span>
            </span>
        </div>
    @endforeach
</div>
