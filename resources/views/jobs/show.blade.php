@extends('statamic::layout')
@section('title', 'AI Pages')

@section('content')
@php
    $running = in_array($job['status'] ?? '', ['queued', 'running'], true);
    $usage = $job['usage'] ?? null;
@endphp

<div class="aip" @if ($running) data-poll="{{ cp_route('ai-pages.jobs.status', $job['id']) }}" @endif>
    <div class="aip-header">
        <div>
            <h1 class="aip-title">{{ $job['title'] ?? $job['entry_title'] ?? 'Build' }}</h1>
            @if ($summary = $job['outline']['summary'] ?? null)
                <p class="aip-lede">{{ $summary }}</p>
            @endif
        </div>
        <a href="{{ cp_route('ai-pages.jobs') }}" class="aip-link">History</a>
    </div>

    @if ($stalled)
        <x-ai-pages::notice type="warning">
            <strong>Nothing has picked this up.</strong>
            <p>
                It was queued on <code class="aip-inline">{{ $queueConnection }}</code> but no worker has
                started it. Run one and it will be collected:
            </p>
            <code class="aip-code">php artisan queue:work {{ $queueConnection }} --tries=1 --timeout=1800</code>
        </x-ai-pages::notice>
    @endif

    @if (($job['status'] ?? null) === 'failed')
        <x-ai-pages::notice type="error">
            <strong>That didn't work.</strong>
            <p>{{ $job['error'] ?? 'Unknown error.' }}</p>
        </x-ai-pages::notice>
    @endif

    @if (($job['status'] ?? null) === 'awaiting_approval')
        <x-ai-pages::notice type="warning">
            <strong>Ready for review.</strong> Nothing has been saved to the page yet.
        </x-ai-pages::notice>
    @endif

    <div class="aip-layout">
        <div class="aip-main">
            @if ($running)
                <div class="aip-card">
                    <div class="aip-working">
                        <span class="aip-spinner" aria-hidden="true"></span>
                        <span>
                            <strong id="aip-progress">{{ $job['progress'] ?? 'Working…' }}</strong>
                            @if (($job['total'] ?? 0) > 0)
                                <span class="aip-meta" style="display:block">Step {{ $job['step'] }} of {{ $job['total'] }}</span>
                            @endif
                        </span>
                    </div>
                </div>
            @endif

            @if (($job['status'] ?? null) === 'awaiting_approval')
                <div class="aip-card">
                    <h2 class="aip-h2">Proposed changes</h2>
                    <p class="aip-prose">{{ $job['patch']['summary'] ?? '' }}</p>

                    @if ($notes = $job['patch']['notes'] ?? null)
                        <p class="aip-hint">{{ $notes }}</p>
                    @endif

                    <ul class="aip-ops" style="margin-top:1rem">
                        @foreach ($job['patch']['operations'] ?? [] as $operation)
                            <li>
                                <code class="aip-inline">{{ $operation['op'] }}</code>
                                @isset($operation['index'])<span class="aip-meta">§{{ $operation['index'] }}</span>@endisset
                                @isset($operation['set'])<code class="aip-inline">{{ $operation['set'] }}</code>@endisset
                                <span style="display:block" class="aip-meta">{{ $operation['reason'] ?? '' }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($job['sections'] ?? null)
                <div class="aip-card">
                    <h2 class="aip-h2">The plan</h2>
                    <ol class="aip-steps">
                        @foreach ($job['sections'] as $i => $section)
                            <li>
                                <span class="aip-steps__n">{{ $i + 1 }}</span>
                                <span>
                                    <code class="aip-inline">{{ $section['set'] }}</code>
                                    @if ($section['heading'] ?? null)<strong> {{ $section['heading'] }}</strong>@endif
                                    <span class="aip-meta" style="display:block">{{ $section['brief'] ?? '' }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endif

            @if ($fidelity = $job['fidelity'] ?? null)
                <div class="aip-card">
                    <h2 class="aip-h2">Wording check</h2>
                    <p>{{ round(($fidelity['score'] ?? 1) * 100) }}% of the output matched the source exactly.</p>

                    @if ($fidelity['drifted'] ?? [])
                        <h3 class="aip-h3" style="margin-top:1.25rem">Reworded — check these</h3>
                        <ul class="aip-quotes">
                            @foreach ($fidelity['drifted'] as $drift)
                                <li>
                                    <span class="aip-meta">§{{ $drift['section'] }} <code class="aip-inline">{{ $drift['set'] }}</code></span>
                                    <span style="display:block">“{{ $drift['output'] }}”</span>
                                    @if ($drift['closest_source'])
                                        <span class="aip-meta" style="display:block">source: “{{ $drift['closest_source'] }}”</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($fidelity['missing'] ?? [])
                        <h3 class="aip-h3" style="margin-top:1.25rem">In the source but not on the page</h3>
                        <ul class="aip-bullets">
                            @foreach ($fidelity['missing'] as $missing)<li>“{{ $missing }}”</li>@endforeach
                        </ul>
                    @endif
                </div>
            @endif

            @if ($companions = $job['companions'] ?? [])
                <div class="aip-card">
                    <h2 class="aip-h2">Supporting entries</h2>
                    <p class="aip-hint" style="margin-bottom:1rem">
                        Written as entries in their own collections and linked from the page.
                    </p>
                    <div class="aip-rows">
                        @foreach ($companions as $companion)
                            <div class="aip-row">
                                <span>
                                    @if ($companion['edit_url'] ?? null)
                                        <a class="aip-link" href="{{ $companion['edit_url'] }}">{{ $companion['title'] }}</a>
                                    @else
                                        {{ $companion['title'] }}
                                    @endif
                                    <span class="aip-tag">{{ $companion['collection'] }}</span>
                                    @if ($companion['reused'] ?? false)<span class="aip-tag">already existed</span>@endif
                                </span>
                                <span class="aip-meta aip-mono">{{ $companion['slug'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($warnings = $job['warnings'] ?? [])
                <div class="aip-card">
                    <h2 class="aip-h2">Things it skipped</h2>
                    <ul class="aip-bullets">
                        @foreach ($warnings as $warning)<li>{{ $warning }}</li>@endforeach
                    </ul>
                </div>
            @endif

            @if ($written = $job['written'] ?? [])
                <div class="aip-card">
                    <h2 class="aip-h2">Instructions written</h2>
                    <div class="aip-rows">
                        @foreach ($written as $file)
                            <div class="aip-row">
                                <a class="aip-link aip-mono" href="{{ cp_route('ai-pages.instructions.edit', $file) }}">{{ $file }}.md</a>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <aside class="aip-side">
            @if ($entry)
                <div class="aip-card aip-card--tight">
                    <h2 class="aip-h2">Draft created</h2>
                    <p class="aip-hint" style="margin-bottom:1rem">Unpublished — read it through before publishing.</p>
                    <a href="{{ $entry->editUrl() }}" class="aip-btn aip-btn--primary aip-btn--block">Open the draft</a>
                </div>
            @endif

            @if (($job['status'] ?? null) === 'awaiting_approval')
                <div class="aip-card aip-card--tight">
                    <form method="POST" action="{{ cp_route('ai-pages.tweak.apply', [$job['entry_id'], $job['id']]) }}">
                        @csrf
                        <button type="submit" class="aip-btn aip-btn--primary aip-btn--block">Apply to the page</button>
                    </form>
                    <a href="{{ cp_route('ai-pages.tweak', $job['entry_id']) }}" class="aip-btn aip-btn--block" style="margin-top:0.5rem">Try a different instruction</a>
                </div>
            @endif

            <div class="aip-card aip-card--tight">
                <h2 class="aip-h2">Run</h2>
                <div class="aip-rows">
                    <div class="aip-row">
                        <span>Status</span>
                        <span class="aip-status aip-status--{{ $job['status'] ?? 'unknown' }}">{{ str_replace('_', ' ', $job['status'] ?? 'unknown') }}</span>
                    </div>
                    @isset($job['collection'])
                        <div class="aip-row"><span>Collection</span><span class="aip-meta">{{ $job['collection'] }}</span></div>
                    @endisset
                    @isset($job['mode'])
                        <div class="aip-row"><span>Mode</span><span class="aip-meta">{{ $job['mode'] }}</span></div>
                    @endisset
                    <div class="aip-row">
                        <span>Started</span>
                        <span class="aip-meta">{{ \Carbon\Carbon::parse($job['created_at'])->diffForHumans() }}</span>
                    </div>
                </div>
            </div>

            @if ($usage)
                <div class="aip-card aip-card--tight">
                    <h2 class="aip-h2">Spend</h2>
                    <div class="aip-rows">
                        <div class="aip-row"><span>API calls</span><span class="aip-meta">{{ $usage['calls'] ?? 0 }}</span></div>
                        <div class="aip-row"><span>Tokens in</span><span class="aip-meta">{{ number_format($usage['input_tokens'] ?? 0) }}</span></div>
                        <div class="aip-row"><span>Tokens out</span><span class="aip-meta">{{ number_format($usage['output_tokens'] ?? 0) }}</span></div>
                        @if (($usage['cache_read_input_tokens'] ?? 0) > 0)
                            <div class="aip-row"><span>Cached</span><span class="aip-meta">{{ number_format($usage['cache_read_input_tokens']) }}</span></div>
                        @endif
                        <div class="aip-row"><span>Roughly</span><span class="aip-meta">${{ number_format($job['cost'] ?? 0, 2) }}</span></div>
                    </div>
                </div>
            @endif
        </aside>
    </div>
</div>

@if ($running)
    @section('scripts')
    <script>
        (function () {
            const root = document.querySelector('[data-poll]');
            if (!root) return;

            const url = root.dataset.poll;
            const label = document.getElementById('aip-progress');

            const tick = () => fetch(url, { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(data => {
                    if (label && data.progress) label.textContent = data.progress;
                    if (data.status === 'queued' || data.status === 'running') {
                        setTimeout(tick, 2500);
                    } else {
                        window.location.reload();
                    }
                })
                .catch(() => setTimeout(tick, 5000));

            setTimeout(tick, 2500);
        })();
    </script>
    @endsection
@endif
@endsection
