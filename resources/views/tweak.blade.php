@extends('statamic::layout')
@section('title', 'Tweak with AI')

@section('content')
<div class="max-w-3xl">
    <h1 class="mb-2">Tweak with AI</h1>
    <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">
        Editing <strong>{{ $entry->get('title') }}</strong>
        <span class="text-gray-400">({{ $entry->collection()->handle() }})</span>
    </p>

    @if ($errors->any())
        <x-ai-pages::notice type="error">
            <ul class="list-disc ml-4">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </x-ai-pages::notice>
    @endif

    <form method="POST" action="{{ cp_route('ai-pages.tweak.store', $entry->id()) }}">
        @csrf

        <div class="card p-6 mb-6">
            <label class="font-medium text-sm block mb-1">What should change?</label>
            <textarea name="instruction" rows="5" class="input-text" autofocus
                placeholder="e.g. Add an FAQ section at the bottom using the existing medication FAQs. Shorten the intro to two paragraphs. Change the hero button to point at /book-a-consultation.">{{ old('instruction') }}</textarea>
            <p class="text-xs text-gray-500 mt-2">
                Be specific about which section you mean. Sections you don't mention are left exactly as they are.
            </p>
        </div>

        <div class="card p-6 mb-6">
            <label class="font-medium text-sm block mb-1">New material <span class="text-gray-400 font-normal">(optional)</span></label>
            <textarea name="text" rows="6" class="input-text font-mono text-sm"
                placeholder="Paste any new copy the change needs…">{{ old('text') }}</textarea>
        </div>

        <div class="flex items-center justify-between">
            <p class="text-xs text-gray-500">You'll see the proposed changes before anything is saved.</p>
            <button type="submit" class="btn-primary">Plan the changes</button>
        </div>
    </form>
</div>
@endsection
