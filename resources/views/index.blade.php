@extends('statamic::layout')
@section('title', __('ai-pages::messages.nav_title'))

@section('content')
<div class="aip">
    <div class="aip-header">
        <div>
            <h1 class="aip-title">{{ __('ai-pages::messages.nav_title') }}</h1>
            <p class="aip-lede">Build pages from a document, a link or a brief — assembled out of this site's own blocks.</p>
        </div>
        @if ($configured)
            <a href="{{ cp_route('ai-pages.build') }}" class="aip-btn aip-btn--primary">Build a page</a>
        @endif
    </div>

    @unless ($configured)
        <x-ai-pages::notice type="error">
            <strong>No API key.</strong> {{ __('ai-pages::messages.no_key') }}
            <code class="aip-code">ANTHROPIC_API_KEY=sk-ant-…
ANTHROPIC_MODEL={{ $model }}</code>
        </x-ai-pages::notice>
    @endunless

    @if ($configured && ! $instructionsReady)
        <x-ai-pages::notice type="warning">
            <strong>This site hasn't been read yet.</strong>
            {{ __('ai-pages::messages.not_swept') }}
            <a href="{{ cp_route('ai-pages.instructions') }}">Run the sweep →</a>
        </x-ai-pages::notice>
    @endif

    <div class="aip-card">
        <h2 class="aip-h2">How it works</h2>
        <ol class="aip-steps-numbered">
            <li><strong>Sweep</strong> — it reads your blueprints, published pages and globals, then writes itself a site profile, a tone-of-voice guide, and a per-collection guide to how your blocks actually get used.</li>
            <li><strong>Build</strong> — give it a document, a link or some text. It plans the page against your real blocks, then fills each one in.</li>
            <li><strong>Review</strong> — everything lands as a draft for you to check before it goes anywhere.</li>
        </ol>
        <p class="aip-meta" style="margin-top:1rem">Model: <code class="aip-inline">{{ $model }}</code></p>
    </div>

    <div class="aip-card">
        <div class="aip-card__head">
            <h2 class="aip-h2">Instructions</h2>
            <a href="{{ cp_route('ai-pages.instructions') }}" class="aip-link">Manage →</a>
        </div>
        <div class="aip-rows">
            @foreach ($inventory as $file)
                <div class="aip-row">
                    <span class="aip-mono">{{ $file['name'] }}.md</span>
                    <span class="aip-meta">
                        @if ($file['exists'])
                            {{ number_format($file['words']) }} words ·
                            {{ \Carbon\Carbon::createFromTimestamp($file['updated_at'])->diffForHumans() }}
                        @else
                            not written yet
                        @endif
                    </span>
                </div>
            @endforeach
        </div>
    </div>

    @if ($recent)
        <div class="aip-card">
            <div class="aip-card__head">
                <h2 class="aip-h2">Recent</h2>
                <a href="{{ cp_route('ai-pages.jobs') }}" class="aip-link">All history →</a>
            </div>
            @include('ai-pages::jobs.table', ['jobs' => $recent])
        </div>
    @endif
</div>
@endsection
