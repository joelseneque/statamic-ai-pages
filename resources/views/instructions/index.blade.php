@extends('statamic::layout')
@section('title', 'AI Pages instructions')

@section('content')
<div class="aip">
    <div class="aip-header">
        <div>
            <h1 class="aip-title">Instructions</h1>
            <p class="aip-lede">
                What AI Pages knows about this site. Plain Markdown in
                <code class="aip-inline">{{ str_replace(base_path().'/', '', config('ai-pages.instructions_path')) }}</code> —
                commit them, and edit by hand wherever the sweep's read isn't quite right.
            </p>
        </div>
    </div>

    @if (session('success'))
        <x-ai-pages::notice type="success">{{ session('success') }}</x-ai-pages::notice>
    @endif

    <div class="aip-layout">
        <div class="aip-main">
            <div class="aip-card">
                <h2 class="aip-h2">Files</h2>
                <div class="aip-rows">
                    @foreach ($inventory as $file)
                        <div class="aip-row">
                            <span>
                                <a href="{{ cp_route('ai-pages.instructions.edit', $file['name']) }}" class="aip-link aip-mono">{{ $file['name'] }}.md</a>
                                @unless ($file['generated'])<span class="aip-tag">yours — never overwritten</span>@endunless
                            </span>
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
        </div>

        <aside class="aip-side">
            <div class="aip-card aip-card--tight">
                <h2 class="aip-h2">Read the site</h2>
                <p class="aip-hint" style="margin-bottom:1rem">
                    Run this after a redesign, a big content change, or when you've added new blocks. Your
                    <code class="aip-inline">house-rules.md</code> is left alone.
                </p>

                @unless ($configured)
                    <x-ai-pages::notice type="error">{{ __('ai-pages::messages.no_key') }}</x-ai-pages::notice>
                @else
                    <form method="POST" action="{{ cp_route('ai-pages.instructions.sweep') }}">
                        @csrf

                        <div class="aip-choices aip-field">
                            <label class="aip-choice">
                                <input type="checkbox" name="parts[]" value="profile" checked>
                                <span>
                                    <span class="aip-choice__title">Site profile</span>
                                    <span class="aip-choice__hint">What the organisation does, the audience, the IA, and the names and URLs it must get right.</span>
                                </span>
                            </label>
                            <label class="aip-choice">
                                <input type="checkbox" name="parts[]" value="tone" checked>
                                <span>
                                    <span class="aip-choice__title">Tone of voice</span>
                                    <span class="aip-choice__hint">How the site sounds, derived from its own published copy.</span>
                                </span>
                            </label>
                            <label class="aip-choice">
                                <input type="checkbox" name="parts[]" value="schema" checked>
                                <span>
                                    <span class="aip-choice__title">Block guides</span>
                                    <span class="aip-choice__hint">Which blocks each collection really uses, in what order and with what settings.</span>
                                </span>
                            </label>
                        </div>

                        <div class="aip-field">
                            <span class="aip-label">Collections to study</span>
                            <div class="aip-checks">
                                @foreach ($collections as $collection)
                                    <label><input type="checkbox" name="collections[]" value="{{ $collection }}" checked> {{ $collection }}</label>
                                @endforeach
                            </div>
                        </div>

                        <div class="aip-field">
                            <label class="aip-label" for="aip-notes">Anything the site can't tell it?</label>
                            <textarea id="aip-notes" name="notes" rows="3" class="aip-textarea"
                                placeholder="e.g. Never quote prices. Australian English."></textarea>
                        </div>

                        <button type="submit" class="aip-btn aip-btn--primary aip-btn--block">Run the sweep</button>
                        <p class="aip-footnote" style="margin-top:0.75rem">
                            Costs a few cents. Also available as <code class="aip-inline">php please ai-pages:sweep</code>.
                        </p>
                    </form>
                @endunless
            </div>
        </aside>
    </div>
</div>
@endsection
