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

        <div class="aip-card">
            <div class="aip-field">
                <label class="aip-label" for="aip-instruction">What should change?</label>
                <textarea id="aip-instruction" name="instruction" rows="5" class="aip-textarea" autofocus
                    placeholder="e.g. Add an FAQ section at the bottom using the existing medication FAQs. Shorten the intro to two paragraphs. Point the hero button at /book-a-consultation.">{{ old('instruction') }}</textarea>
                <p class="aip-hint">Be specific about which section you mean. Sections you don't mention are left exactly as they are.</p>
            </div>
        </div>

        <div class="aip-card">
            <div class="aip-field">
                <label class="aip-label" for="aip-newtext">New material <span class="aip-label__optional">(optional)</span></label>
                <textarea id="aip-newtext" name="text" rows="6" class="aip-textarea aip-textarea--mono"
                    placeholder="Paste any new copy the change needs…">{{ old('text') }}</textarea>
            </div>
        </div>

        <div class="aip-actions aip-actions--split">
            <p class="aip-footnote">You'll see the proposed changes before anything is saved.</p>
            <button type="submit" class="aip-btn aip-btn--primary">Plan the changes</button>
        </div>
    </form>
</div>
@endsection
