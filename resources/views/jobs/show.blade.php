@extends('statamic::layout')
@section('title', 'AI Pages')

@section('content')
@php
    $running = in_array($job['status'] ?? '', ['queued', 'running'], true);
@endphp

<div class="max-w-4xl" @if ($running) data-poll="{{ cp_route('ai-pages.jobs.status', $job['id']) }}" @endif>
    <div class="flex items-center justify-between mb-6">
        <h1>{{ $job['title'] ?? $job['entry_title'] ?? 'Build' }}</h1>
        <a href="{{ cp_route('ai-pages.jobs') }}" class="text-sm text-blue-600 hover:underline">History</a>
    </div>

    @if ($running)
        <div class="card p-6 mb-6">
            <div class="flex items-center gap-3">
                <svg class="animate-spin h-5 w-5 text-blue-500" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                </svg>
                <div>
                    <div class="font-medium" id="ai-pages-progress">{{ $job['progress'] ?? 'Working…' }}</div>
                    @if (($job['total'] ?? 0) > 0)
                        <div class="text-xs text-gray-500">Step {{ $job['step'] }} of {{ $job['total'] }}</div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    @if (($job['status'] ?? null) === 'failed')
        <x-ai-pages::notice type="error">
            <strong>That didn't work.</strong>
            <p class="mt-1">{{ $job['error'] ?? 'Unknown error.' }}</p>
        </x-ai-pages::notice>
    @endif

    @if (($job['status'] ?? null) === 'awaiting_approval')
        <x-ai-pages::notice type="warning">
            <strong>Ready for review.</strong> Nothing has been saved to the page yet.
        </x-ai-pages::notice>

        <div class="card p-6 mb-6">
            <h2 class="mb-2">Proposed changes</h2>
            <p class="text-sm mb-4">{{ $job['patch']['summary'] ?? '' }}</p>

            @if ($notes = $job['patch']['notes'] ?? null)
                <p class="text-sm text-amber-700 dark:text-amber-300 mb-4">{{ $notes }}</p>
            @endif

            <ol class="space-y-2 text-sm">
                @foreach ($job['patch']['operations'] ?? [] as $operation)
                    <li class="border-l-2 border-blue-400 pl-3">
                        <code class="text-xs">{{ $operation['op'] }}</code>
                        @isset($operation['index']) <span class="text-gray-500">§{{ $operation['index'] }}</span> @endisset
                        @isset($operation['set']) <code class="text-xs">{{ $operation['set'] }}</code> @endisset
                        <div class="text-gray-600 dark:text-gray-400">{{ $operation['reason'] ?? '' }}</div>
                    </li>
                @endforeach
            </ol>

            <div class="flex items-center gap-3 mt-6">
                <form method="POST" action="{{ cp_route('ai-pages.tweak.apply', [$job['entry_id'], $job['id']]) }}">
                    @csrf
                    <button type="submit" class="btn-primary">Apply to the page</button>
                </form>
                <a href="{{ cp_route('ai-pages.tweak', $job['entry_id']) }}" class="btn">Try a different instruction</a>
            </div>
        </div>
    @endif

    @if ($entry)
        <div class="card p-6 mb-6">
            <h2 class="mb-2">Draft created</h2>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                Saved as an unpublished draft — read it through before publishing.
            </p>
            <a href="{{ $entry->editUrl() }}" class="btn-primary">Open the draft</a>
        </div>
    @endif

    @if ($outline = $job['outline'] ?? null)
        <div class="card p-6 mb-6">
            <h2 class="mb-2">The plan</h2>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">{{ $outline['summary'] ?? '' }}</p>

            <ol class="space-y-2 text-sm">
                @foreach ($job['sections'] ?? [] as $i => $section)
                    <li class="flex gap-3">
                        <span class="text-gray-400 w-6 text-right">{{ $i + 1 }}</span>
                        <span>
                            <code class="text-xs">{{ $section['set'] }}</code>
                            @if ($section['heading'] ?? null)
                                <strong class="ml-1">{{ $section['heading'] }}</strong>
                            @endif
                            <span class="block text-xs text-gray-500">{{ $section['brief'] ?? '' }}</span>
                        </span>
                    </li>
                @endforeach
            </ol>
        </div>
    @endif

    @if ($fidelity = $job['fidelity'] ?? null)
        <div class="card p-6 mb-6">
            <h2 class="mb-2">Wording check</h2>
            <p class="text-sm mb-4">
                {{ round(($fidelity['score'] ?? 1) * 100) }}% of the output matched the source exactly.
            </p>

            @if ($fidelity['drifted'] ?? [])
                <h3 class="text-sm font-medium mb-2">Reworded — check these</h3>
                <ul class="space-y-2 text-xs mb-4">
                    @foreach ($fidelity['drifted'] as $drift)
                        <li class="border-l-2 border-amber-400 pl-3">
                            <div>§{{ $drift['section'] }} <code>{{ $drift['set'] }}</code></div>
                            <div class="text-gray-700 dark:text-gray-300">“{{ $drift['output'] }}”</div>
                            @if ($drift['closest_source'])
                                <div class="text-gray-500">source: “{{ $drift['closest_source'] }}”</div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($fidelity['missing'] ?? [])
                <h3 class="text-sm font-medium mb-2">In the source but not on the page</h3>
                <ul class="list-disc ml-5 text-xs space-y-1">
                    @foreach ($fidelity['missing'] as $missing)
                        <li class="text-gray-600 dark:text-gray-400">“{{ $missing }}”</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    @if ($warnings = $job['warnings'] ?? [])
        <div class="card p-6 mb-6">
            <h2 class="mb-2">Things it skipped</h2>
            <ul class="list-disc ml-5 text-sm space-y-1 text-gray-600 dark:text-gray-400">
                @foreach ($warnings as $warning)<li>{{ $warning }}</li>@endforeach
            </ul>
        </div>
    @endif

    @if ($written = $job['written'] ?? [])
        <div class="card p-6 mb-6">
            <h2 class="mb-2">Instructions written</h2>
            <ul class="text-sm space-y-1">
                @foreach ($written as $file)
                    <li><a class="text-blue-600 hover:underline" href="{{ cp_route('ai-pages.instructions.edit', $file) }}">{{ $file }}.md</a></li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($usage = $job['usage'] ?? null)
        <p class="text-xs text-gray-500">
            {{ $usage['calls'] ?? 0 }} API calls ·
            {{ number_format($usage['input_tokens'] ?? 0) }} in /
            {{ number_format($usage['output_tokens'] ?? 0) }} out
            @if (($usage['cache_read_input_tokens'] ?? 0) > 0)
                · {{ number_format($usage['cache_read_input_tokens']) }} cached
            @endif
            · roughly ${{ number_format($job['cost'] ?? 0, 2) }}
        </p>
    @endif
</div>

@if ($running)
    @push('scripts')
    <script>
        (function () {
            const root = document.querySelector('[data-poll]');
            if (!root) return;

            const url = root.dataset.poll;
            const label = document.getElementById('ai-pages-progress');

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
    @endpush
@endif
@endsection
