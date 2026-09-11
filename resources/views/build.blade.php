@extends('statamic::layout')
@section('title', 'Build a page')

@section('content')
<div class="aip">
    <div class="aip-header">
        <div>
            <h1 class="aip-title">Build a page</h1>
            <p class="aip-lede">It plans the page against this site's real blocks, then fills each one in.</p>
        </div>
    </div>

    @if ($runsInline)
        <x-ai-pages::inline-warning :suggested="$suggestedQueue" />
    @endif

    @unless ($instructionsReady)
        <x-ai-pages::notice type="warning">
            AI Pages hasn't read this site yet, so it has no profile or tone of voice to work from. It will still
            build, but the output will be generic.
            <a href="{{ cp_route('ai-pages.instructions') }}">Run the sweep first →</a>
        </x-ai-pages::notice>
    @endunless

    @if ($errors->any())
        <x-ai-pages::notice type="error">
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-ai-pages::notice>
    @endif

    <form method="POST" action="{{ cp_route('ai-pages.build.store') }}" enctype="multipart/form-data">
        @csrf

        <div class="aip-layout">
            <div class="aip-main">
                <div class="aip-card">
                    <h2 class="aip-h2">What are we building from?</h2>
                    <p class="aip-hint" style="margin-bottom:1rem">
                        Anything goes — paste the copy, drop in a Word doc or PDF, link a Google Doc, or point at a
                        page that already exists. Combine as many as you like.
                    </p>

                    <div class="aip-field">
                        <label class="aip-label" for="aip-text">Paste text</label>
                        <textarea id="aip-text" name="text" rows="16" class="aip-textarea aip-textarea--mono"
                            placeholder="Paste the copy, an outline, or a brief…">{{ old('text') }}</textarea>
                    </div>

                    <div class="aip-field">
                        <label class="aip-label" for="aip-urls">Links</label>
                        <textarea id="aip-urls" name="urls" rows="3" class="aip-textarea"
                            placeholder="https://docs.google.com/document/d/…&#10;https://example.com/the-old-page">{{ old('urls') }}</textarea>
                        <p class="aip-hint">One per line. Google Docs, Sheets and Slides must be shared as “anyone with the link can view”.</p>
                    </div>

                    <div class="aip-field">
                        <label class="aip-label" for="aip-files">Files</label>
                        <input id="aip-files" type="file" name="files[]" multiple class="aip-file"
                            accept="{{ collect($allowedExtensions)->map(fn($e) => '.'.$e)->implode(',') }}">
                        <p class="aip-hint">
                            Up to 10 files, {{ $maxUploadMb }}MB each. PDFs and images are read directly, so a
                            design export or a screenshot works as well as a document does.
                        </p>
                    </div>
                </div>

                <div class="aip-card">
                    <div class="aip-field">
                        <label class="aip-label" for="aip-brief">Anything else it should know?</label>
                        <textarea id="aip-brief" name="brief" rows="3" class="aip-textarea"
                            placeholder="e.g. This replaces the old services page. Keep the FAQ block at the bottom. Link the CTA to /contact-us.">{{ old('brief') }}</textarea>
                    </div>
                </div>
            </div>

            <aside class="aip-side">
                <div class="aip-card aip-card--tight">
                    <h2 class="aip-h2">How much licence?</h2>

                    <div class="aip-choices">
                        @foreach ($modes as $value => $label)
                            <label class="aip-choice">
                                <input type="radio" name="mode" value="{{ $value }}" @checked(old('mode', 'polish') === $value)>
                                <span>
                                    <span class="aip-choice__title">{{ $label }}</span>
                                    <span class="aip-choice__hint">
                                        @switch($value)
                                            @case('verbatim')
                                                Your words, untouched. It only decides which block each part goes
                                                in, then checks the result back against the source.
                                                @break
                                            @case('polish')
                                                Keeps your sentences and meaning; fixes grammar, spelling and headings.
                                                @break
                                            @default
                                                Treats the source as a brief and writes the page in your tone of voice.
                                        @endswitch
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="aip-card aip-card--tight">
                    <h2 class="aip-h2">Where does it go?</h2>

                    <div class="aip-field">
                        <label class="aip-label" for="aip-collection">Collection</label>
                        <select id="aip-collection" name="collection" class="aip-select">
                            @foreach ($collections as $handle => $title)
                                <option value="{{ $handle }}" @selected(old('collection') === $handle)>{{ $title }}</option>
                            @endforeach
                        </select>
                    </div>

                    @if ($sites->count() > 1)
                        <div class="aip-field">
                            <label class="aip-label" for="aip-site">Site</label>
                            <select id="aip-site" name="site" class="aip-select">
                                @foreach ($sites as $site)
                                    <option value="{{ $site['handle'] }}">{{ $site['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div class="aip-field">
                        <label class="aip-label" for="aip-title">Title <span class="aip-label__optional">(optional)</span></label>
                        <input id="aip-title" type="text" name="title" value="{{ old('title') }}" class="aip-input"
                            placeholder="It will choose one">
                    </div>

                    <div class="aip-field">
                        <label class="aip-label" for="aip-max">Max sections <span class="aip-label__optional">(optional)</span></label>
                        <input id="aip-max" type="number" name="max_sections" value="{{ old('max_sections') }}" min="1" max="30" class="aip-input">
                    </div>
                </div>

                <div class="aip-card aip-card--tight">
                    <button type="submit" class="aip-btn aip-btn--primary aip-btn--block">Build the draft</button>
                    <p class="aip-footnote" style="margin-top:0.75rem">Saved as an unpublished draft. Nothing goes live.</p>
                </div>
            </aside>
        </div>
    </form>
</div>
@endsection
