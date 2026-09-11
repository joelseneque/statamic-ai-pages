@extends('statamic::layout')
@section('title', 'Build a page')

@section('content')
<div class="max-w-3xl">
    <h1 class="mb-6">Build a page</h1>

    @unless ($instructionsReady)
        <x-ai-pages::notice type="warning">
            AI Pages hasn't read this site yet, so it has no profile or tone of voice to work from. It will still
            build, but the output will be generic.
            <a class="underline font-medium" href="{{ cp_route('ai-pages.instructions') }}">Run the sweep first →</a>
        </x-ai-pages::notice>
    @endunless

    @if ($errors->any())
        <x-ai-pages::notice type="error">
            <ul class="list-disc ml-4">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </x-ai-pages::notice>
    @endif

    <form method="POST" action="{{ cp_route('ai-pages.build.store') }}" enctype="multipart/form-data">
        @csrf

        <div class="card p-6 mb-6">
            <h2 class="mb-4">What are we building from?</h2>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                Anything goes — paste the copy, drop in a Word doc or PDF, link a Google Doc, or point at a page
                that already exists somewhere. Combine as many as you like.
            </p>

            <div class="mb-4">
                <label class="font-medium text-sm block mb-1">Paste text</label>
                <textarea name="text" rows="10" class="input-text font-mono text-sm"
                    placeholder="Paste the copy, an outline, or a brief…">{{ old('text') }}</textarea>
            </div>

            <div class="mb-4">
                <label class="font-medium text-sm block mb-1">Links</label>
                <textarea name="urls" rows="3" class="input-text text-sm"
                    placeholder="https://docs.google.com/document/d/…&#10;https://example.com/the-old-page">{{ old('urls') }}</textarea>
                <p class="text-xs text-gray-500 mt-1">
                    One per line. Google Docs, Sheets and Slides need to be shared as "anyone with the link can view".
                </p>
            </div>

            <div>
                <label class="font-medium text-sm block mb-1">Files</label>
                <input type="file" name="files[]" multiple class="text-sm"
                    accept="{{ collect($allowedExtensions)->map(fn($e) => '.'.$e)->implode(',') }}">
                <p class="text-xs text-gray-500 mt-1">
                    Up to 10 files, {{ $maxUploadMb }}MB each. PDFs and images are read directly, so a design
                    export or a screenshot works as well as a document.
                </p>
            </div>
        </div>

        <div class="card p-6 mb-6">
            <h2 class="mb-4">How much licence?</h2>

            <div class="space-y-3">
                @foreach ($modes as $value => $label)
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="radio" name="mode" value="{{ $value }}"
                            @checked(old('mode', 'polish') === $value) class="mt-1">
                        <span>
                            <span class="font-medium text-sm">{{ $label }}</span>
                            <span class="block text-xs text-gray-500">
                                @switch($value)
                                    @case('verbatim')
                                        Your words, untouched. It only decides which block each part goes in.
                                        Output is checked back against the source and anything that drifted is flagged.
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

        <div class="card p-6 mb-6">
            <h2 class="mb-4">Where does it go?</h2>

            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="font-medium text-sm block mb-1">Collection</label>
                    <select name="collection" class="input-text">
                        @foreach ($collections as $handle => $title)
                            <option value="{{ $handle }}" @selected(old('collection') === $handle)>{{ $title }}</option>
                        @endforeach
                    </select>
                </div>

                @if ($sites->count() > 1)
                    <div>
                        <label class="font-medium text-sm block mb-1">Site</label>
                        <select name="site" class="input-text">
                            @foreach ($sites as $site)
                                <option value="{{ $site['handle'] }}">{{ $site['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            </div>

            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="font-medium text-sm block mb-1">Title <span class="text-gray-400 font-normal">(optional)</span></label>
                    <input type="text" name="title" value="{{ old('title') }}" class="input-text"
                        placeholder="Leave blank and it will choose one">
                </div>
                <div>
                    <label class="font-medium text-sm block mb-1">Max sections <span class="text-gray-400 font-normal">(optional)</span></label>
                    <input type="number" name="max_sections" value="{{ old('max_sections') }}" min="1" max="30" class="input-text">
                </div>
            </div>

            <div>
                <label class="font-medium text-sm block mb-1">Anything else it should know?</label>
                <textarea name="brief" rows="3" class="input-text text-sm"
                    placeholder="e.g. This replaces the old services page. Keep the FAQ block at the bottom. Link to /contact-us for the CTA.">{{ old('brief') }}</textarea>
            </div>
        </div>

        <div class="flex items-center justify-between">
            <p class="text-xs text-gray-500">Saved as an unpublished draft. Nothing goes live.</p>
            <button type="submit" class="btn-primary">Build the draft</button>
        </div>
    </form>
</div>
@endsection
