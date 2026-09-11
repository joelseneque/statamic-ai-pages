@extends('statamic::layout')
@section('title', __('ai-pages::messages.nav_title'))

@section('content')
<div class="max-w-4xl">
    <div class="flex items-center justify-between mb-6">
        <h1>{{ __('ai-pages::messages.nav_title') }}</h1>
        @if ($configured)
            <a href="{{ cp_route('ai-pages.build') }}" class="btn-primary">Build a page</a>
        @endif
    </div>

    @unless ($configured)
        <x-ai-pages::notice type="error">
            <strong>No API key.</strong>
            {{ __('ai-pages::messages.no_key') }}
            <pre class="mt-2 text-xs">ANTHROPIC_API_KEY=sk-ant-…
ANTHROPIC_MODEL={{ $model }}</pre>
        </x-ai-pages::notice>
    @endunless

    @if ($configured && ! $instructionsReady)
        <x-ai-pages::notice type="warning">
            <strong>This site hasn't been read yet.</strong>
            {{ __('ai-pages::messages.not_swept') }}
            <a class="underline font-medium" href="{{ cp_route('ai-pages.instructions') }}">Run the sweep →</a>
        </x-ai-pages::notice>
    @endif

    <div class="card p-6 mb-6">
        <h2 class="mb-2">How it works</h2>
        <ol class="list-decimal ml-5 space-y-1 text-sm text-gray-700 dark:text-gray-300">
            <li><strong>Sweep</strong> — AI Pages reads your blueprints, your published pages and your globals, and writes itself a site profile, a tone-of-voice guide and a per-collection guide to how your blocks get used.</li>
            <li><strong>Build</strong> — give it a document, a link or some text. It plans the page against your real blocks, then fills each one in.</li>
            <li><strong>Review</strong> — everything lands as a draft entry for you to check before it goes anywhere.</li>
        </ol>
        <p class="text-xs text-gray-500 mt-4">Model: <code>{{ $model }}</code></p>
    </div>

    <div class="card p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
            <h2>Instructions</h2>
            <a href="{{ cp_route('ai-pages.instructions') }}" class="text-sm text-blue-600 hover:underline">Manage →</a>
        </div>
        <div class="space-y-2 text-sm">
            @foreach ($inventory as $file)
                <div class="flex items-center justify-between py-1 border-b border-gray-100 dark:border-dark-600 last:border-0">
                    <span class="font-mono text-xs">{{ $file['name'] }}.md</span>
                    <span class="text-gray-500">
                        @if ($file['exists'])
                            {{ number_format($file['words']) }} words ·
                            {{ \Carbon\Carbon::createFromTimestamp($file['updated_at'])->diffForHumans() }}
                        @else
                            <span class="text-amber-600">not written yet</span>
                        @endif
                    </span>
                </div>
            @endforeach
        </div>
    </div>

    @if ($recent)
        <div class="card p-6">
            <div class="flex items-center justify-between mb-4">
                <h2>Recent</h2>
                <a href="{{ cp_route('ai-pages.jobs') }}" class="text-sm text-blue-600 hover:underline">All history →</a>
            </div>
            @include('ai-pages::jobs.table', ['jobs' => $recent])
        </div>
    @endif
</div>
@endsection
