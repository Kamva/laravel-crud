{{-- Stock detail-view template. Apps are expected to publish + customise
     this (php artisan vendor:publish --tag=kamva-crud-views). Receives:

     $title       - page title (string)
     $model       - the bound model instance
     $sections    - array<string $heading, string|Renderable $content> rendered in the main column
     $sidebars    - array<string $heading, string|Renderable $content> rendered in the right column
     $timeline    - Collection<TimelineEvent>|null
     $editRoute   - URL string|null
     $deleteRoute - URL string|null
--}}
@extends('layouts.app')
@section('title', $title)
@section('content')
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">{{ $title }}</h4>
        <div>
            @if ($editRoute)
                <a href="{{ $editRoute }}" class="btn btn-sm btn-outline-secondary">@lang('Edit')</a>
            @endif
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8 col-md-12">
            @foreach ($sections as $heading => $content)
                <div class="card mb-3">
                    @if (is_string($heading) && !is_numeric($heading))
                        <div class="card-header"><h5 class="mb-0">{{ $heading }}</h5></div>
                    @endif
                    <div class="card-body">
                        @if ($content instanceof \Illuminate\Contracts\Support\Renderable)
                            {!! $content->render() !!}
                        @else
                            {!! $content !!}
                        @endif
                    </div>
                </div>
            @endforeach

            @if ($timeline && $timeline->isNotEmpty())
                <div class="card">
                    <div class="card-header"><h5 class="mb-0">@lang('Activity')</h5></div>
                    <div class="card-body">
                        <ul class="list-unstyled mb-0">
                            @foreach ($timeline as $event)
                                <li class="d-flex justify-content-between border-bottom pb-2 mb-2">
                                    <div>
                                        <strong>{{ $event->title }}</strong>
                                        @if ($event->body)
                                            <div class="small text-muted">{{ $event->body }}</div>
                                        @endif
                                        @if ($event->actor)
                                            <div class="small text-muted">{{ $event->actor }}</div>
                                        @endif
                                    </div>
                                    <small class="text-muted">{{ $event->at->format('Y-m-d H:i') }}</small>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif
        </div>

        <div class="col-lg-4 col-md-12">
            @foreach ($sidebars as $heading => $content)
                <div class="card mb-3">
                    @if (is_string($heading) && !is_numeric($heading))
                        <div class="card-header"><h6 class="mb-0">{{ $heading }}</h6></div>
                    @endif
                    <div class="card-body">
                        @if ($content instanceof \Illuminate\Contracts\Support\Renderable)
                            {!! $content->render() !!}
                        @else
                            {!! $content !!}
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
@endsection
