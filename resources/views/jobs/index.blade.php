@extends('statamic::layout')
@section('title', 'AI Pages history')

@section('content')
<div class="max-w-4xl">
    <h1 class="mb-6">History</h1>

    <div class="card p-6">
        @if ($jobs)
            @include('ai-pages::jobs.table', ['jobs' => $jobs])
        @else
            <p class="text-sm text-gray-500">Nothing yet.</p>
        @endif
    </div>
</div>
@endsection
