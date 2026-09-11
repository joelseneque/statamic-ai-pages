@extends('statamic::layout')
@section('title', 'Tweak with AI')

@section('content')
<div class="aip">
    <div class="aip-header">
        <div>
            <h1 class="aip-title">Tweak with AI</h1>
            <p class="aip-lede">
                Editing <strong>{{ $entry->get('title') }}</strong>
                <span class="aip-meta">({{ $entry->collection()->handle() }})</span>
            </p>
        </div>
    </div>

    @if ($errors->any())
        <x-ai-pages::notice type="error">
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-ai-pages::notice>
    @endif

    <form method="POST" action="{{ cp_route('ai-pages.tweak.store', $entry->id()) }}">
        @csrf

        <div class="aip-layout">
            <div class="aip-main">
                <div class="aip-card">
                    <div class="aip-field">
                        <label class="aip-label" for="aip-instruction">What should change?</label>
                        <textarea id="aip-instruction" name="instruction" rows="8" class="aip-textarea" autofocus
                            placeholder="e.g. Add an FAQ section at the bottom using the existing medication FAQs. Shorten the intro to two paragraphs. Point the hero button at /book-a-consultation.">{{ old('instruction') }}</textarea>
                        <p class="aip-hint">Be specific about which section you mean. Sections you don't mention are left exactly as they are.</p>
                    </div>
                </div>

                <div class="aip-card">
                    <div class="aip-field">
                        <label class="aip-label" for="aip-newtext">New material <span class="aip-label__optional">(optional)</span></label>
                        <textarea id="aip-newtext" name="text" rows="8" class="aip-textarea aip-textarea--mono"
                            placeholder="Paste any new copy the change needs…">{{ old('text') }}</textarea>
                    </div>
                </div>
            </div>

            <aside class="aip-side">
                <div class="aip-card aip-card--tight">
                    <h2 class="aip-h2">The page</h2>
                    <div class="aip-rows">
                        <div class="aip-row">
                            <span>Status</span>
                            <span class="aip-status aip-status--{{ $entry->published() ? 'completed' : 'awaiting_approval' }}">{{ $entry->published() ? 'published' : 'draft' }}</span>
                        </div>
                        <div class="aip-row">
                            <span>Collection</span>
                            <span class="aip-meta">{{ $entry->collection()->handle() }}</span>
                        </div>
                        <div class="aip-row">
                            <span>Blueprint</span>
                            <span class="aip-meta">{{ $entry->blueprint()->handle() }}</span>
                        </div>
                    </div>
                    <div class="aip-actions" style="margin-top:1rem">
                        <a href="{{ $entry->editUrl() }}" class="aip-link">Open in the editor →</a>
                    </div>
                </div>

                <div class="aip-card aip-card--tight">
                    <button type="submit" class="aip-btn aip-btn--primary aip-btn--block">Plan the changes</button>
                    <p class="aip-footnote" style="margin-top:0.75rem">You'll see the proposed changes before anything is saved.</p>
                </div>
            </aside>
        </div>
    </form>
</div>
@endsection
