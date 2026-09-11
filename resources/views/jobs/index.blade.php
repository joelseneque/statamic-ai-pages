@extends('statamic::layout')
@section('title', 'AI Pages history')

@section('content')
<div class="aip">
    <div class="aip-header"><h1 class="aip-title">History</h1></div>

    <div class="aip-card">
        @if ($jobs)
            @include('ai-pages::jobs.table', ['jobs' => $jobs])
        @else
            <p class="aip-meta" style="margin:0">Nothing yet.</p>
        @endif
    </div>
</div>
@endsection
