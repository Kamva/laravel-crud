{{-- Stock kanban template. Apps publish + customise:
        php artisan vendor:publish --tag=kamva-crud-views

     Receives:
       $title            - page title
       $columns          - array<string $key, array{key, definition, cards, count, value_sum}>
       $kanban           - the registered KanbanConfig (for any extra rendering)
       $transitionUrl    - URL template with __ID__ placeholder for the lead key
       $transitionParam  - form field name the drop handler should post (e.g. 'to_stage')
       $topActions       - page-header buttons (see addTopAction)
       $filters          - filter widgets (excluding hidden filters)
       $createRoute      - URL for creating new items (nullable)

     Apps include their own SortableJS bundle and target the `.kamva-kanban-col-body`
     element. See docs/kanban.md for an example JS init.
--}}
@extends('layouts.app')
@section('title', $title)
@section('content')
@include('kamva-crud::_dark_mode')
@php $kcDark = \Kamva\Crud\KamvaCrud::getDarkMode(); @endphp
<div class="kamva-crud-wrap container-fluid py-3"{{ $kcDark !== 'auto' ? ' data-kc-dark="'.e($kcDark).'"' : '' }}>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">{{ $title }}</h4>
        <div>
            @if ($createRoute)
                <a href="{{ $createRoute }}" class="btn btn-sm btn-primary">+ @lang('New')</a>
            @endif
            @foreach ($topActions as $a)
                <a href="{{ $a['url'] }}" class="btn {{ $a['class'] }}">
                    @if ($a['icon']) <i class="{{ $a['icon'] }}"></i> @endif
                    {{ $a['caption'] }}
                </a>
            @endforeach
        </div>
    </div>

    @if (count($filters) > 0)
        <form method="GET" class="row mb-3">
            <input type="hidden" name="view" value="kanban">
            @foreach ($filters as $filter)
                <div class="col-md-3 col-sm-6">{!! $filter->render() !!}</div>
            @endforeach
            <div class="col-md-3 col-sm-6 align-self-end">
                <button type="submit" class="btn btn-sm btn-secondary">@lang('Apply')</button>
            </div>
        </form>
    @endif

    <div class="kamva-kanban-board" style="display:flex;gap:12px;overflow-x:auto;padding-bottom:1rem;align-items:flex-start;">
        @foreach ($columns as $col)
            @php
                $def = $col['definition'];
                $color = $def['color'] ?? 'secondary';
                $acceptsDrop = $def['accepts_drop'] ?? true;
            @endphp
            <div class="kamva-kanban-col" style="flex:0 0 240px;max-width:240px;border-radius:6px;padding:8px;">
                <div class="kamva-kanban-col-header d-flex justify-content-between align-items-center mb-2 px-1">
                    <span class="font-weight-bold">{{ $def['label'] ?? $col['key'] }}</span>
                    <small class="text-muted">
                        {{ $col['count'] }}
                        @if ($col['value_sum'] > 0)
                            · {{ number_format($col['value_sum'], 0) }}
                        @endif
                    </small>
                </div>
                <div class="kamva-kanban-col-body" data-stage="{{ $col['key'] }}" data-accepts-drop="{{ $acceptsDrop ? '1' : '0' }}" style="min-height:80px;">
                    @foreach ($col['cards'] as $card)
                        <div class="kamva-kanban-card" data-id="{{ $card['id'] ?? '' }}"
                             style="border:1px solid;border-radius:4px;padding:8px 10px;margin-bottom:8px;cursor:grab;font-size:0.875rem;">
                            @if (!empty($card['href']))
                                <a href="{{ $card['href'] }}" draggable="false" style="color:inherit;text-decoration:none;display:block;">
                            @endif
                                @if (!empty($card['title']))
                                    <div style="font-weight:600;margin-bottom:2px;">{{ $card['title'] }}</div>
                                @endif
                                @if (!empty($card['subtitle']))
                                    <div class="text-truncate kc-muted" style="font-size:0.75rem;">{{ $card['subtitle'] }}</div>
                                @endif
                                @if (!empty($card['body']))
                                    <div class="kc-muted" style="font-size:0.75rem;margin-top:4px;">{{ $card['body'] }}</div>
                                @endif
                                @if (isset($card['value']) && $card['value'] !== null)
                                    <div style="font-size:0.75rem;font-weight:600;margin-top:6px;">{{ $card['value'] }}</div>
                                @endif
                            @if (!empty($card['href']))
                                </a>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    @if (collect($columns)->sum('count') === 0)
        <div class="alert alert-light text-center mt-3">
            {{ $kanban->emptyMessage ?? __('No items.') }}
        </div>
    @endif

    {{-- App-side JS reads these globals to wire SortableJS up. See docs/kanban.md --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script>
        window.KAMVA_KANBAN_TRANSITION_URL = "{{ $transitionUrl }}";
        window.KAMVA_KANBAN_TRANSITION_PARAM = "{{ $transitionParam }}";
    </script>
</div>
@endsection
