@extends('statamic::layout')
@section('title', 'AI Pages instructions')

@section('content')
<div class="max-w-4xl">
    <h1 class="mb-2">Instructions</h1>
    <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">
        What AI Pages knows about this site. These are plain Markdown files in
        <code>{{ str_replace(base_path().'/', '', config('ai-pages.instructions_path')) }}</code> —
        commit them, and edit them by hand whenever the sweep's read of the site isn't quite right.
    </p>

    @if (session('success'))
        <x-ai-pages::notice type="success">{{ session('success') }}</x-ai-pages::notice>
    @endif

    <div class="card p-6 mb-6">
        <h2 class="mb-4">Files</h2>
        <div class="space-y-1 text-sm">
            @foreach ($inventory as $file)
                <div class="flex items-center justify-between py-2 border-b border-gray-100 dark:border-dark-600 last:border-0">
                    <div>
                        <a href="{{ cp_route('ai-pages.instructions.edit', $file['name']) }}"
                           class="font-mono text-xs text-blue-600 hover:underline">{{ $file['name'] }}.md</a>
                        @unless ($file['generated'])
                            <span class="ml-2 text-xs px-1.5 py-0.5 rounded bg-gray-100 dark:bg-dark-600">yours — never overwritten</span>
                        @endunless
                    </div>
                    <span class="text-gray-500 text-xs">
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

    <div class="card p-6">
        <h2 class="mb-2">Read the site</h2>
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
            Reads your blueprints, published entries, navigation and globals, then writes the guides below.
            Run it after a redesign, a big content change, or when you've added new blocks.
            Anything you've written in <code>house-rules.md</code> is left alone.
        </p>

        @unless ($configured)
            <x-ai-pages::notice type="error">{{ __('ai-pages::messages.no_key') }}</x-ai-pages::notice>
        @else
            <form method="POST" action="{{ cp_route('ai-pages.instructions.sweep') }}">
                @csrf

                <div class="space-y-2 mb-4 text-sm">
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="parts[]" value="profile" checked class="mt-1">
                        <span><strong>Site profile</strong> — what the organisation does, the audience, the IA, the names and URLs it must get right.</span>
                    </label>
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="parts[]" value="tone" checked class="mt-1">
                        <span><strong>Tone of voice</strong> — how the site sounds, derived from its own published copy.</span>
                    </label>
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="parts[]" value="schema" checked class="mt-1">
                        <span><strong>Block guides</strong> — which blocks each collection really uses, in what order, with what settings. Also measures the conventions the builder applies automatically.</span>
                    </label>
                </div>

                <div class="mb-4">
                    <label class="font-medium text-sm block mb-1">Collections to study</label>
                    <div class="flex flex-wrap gap-3 text-sm">
                        @foreach ($collections as $collection)
                            <label class="flex items-center gap-1.5">
                                <input type="checkbox" name="collections[]" value="{{ $collection }}" checked>
                                <span>{{ $collection }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="mb-4">
                    <label class="font-medium text-sm block mb-1">Anything the site can't tell it?</label>
                    <textarea name="notes" rows="3" class="input-text text-sm"
                        placeholder="e.g. We're a specialist obesity clinic in Perth. Never quote prices. Australian English."></textarea>
                </div>

                <button type="submit" class="btn-primary">Run the sweep</button>
                <p class="text-xs text-gray-500 mt-2">
                    Costs a few cents and takes a minute or two. You can also run
                    <code>php please ai-pages:sweep</code>.
                </p>
            </form>
        @endunless
    </div>
</div>
@endsection
